import json
import os
import shlex
import subprocess
import argparse
import logging
import sys
import time

try:
    import docker
    import yaml
    from docker.errors import DockerException
except ImportError as e:
    print(f"ERROR: missing required Python dependency: {e.name}", file=sys.stderr)
    print("Run: python3 -m pip install -r /usr/local/emhttp/plugins/appdatasync/requirements.txt", file=sys.stderr)
    sys.exit(1)

try:
    from colorlog import ColoredFormatter
except ImportError:
    ColoredFormatter = None

from pathlib import Path

DEFAULT_CONFIG_FILE = '/boot/config/plugins/appdatasync/config.yaml'
LOCK_FILE = '/tmp/unraid_appdata_backup.lock'

# Setting up logging
_handler = logging.StreamHandler()
if sys.stderr.isatty() and ColoredFormatter is not None:
    _handler.setFormatter(ColoredFormatter(
        fmt='%(log_color)s[%(asctime)s] [%(levelname)s] %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S',
        log_colors={
            'DEBUG':    'cyan',
            'INFO':     'green',
            'WARNING':  'yellow',
            'ERROR':    'red',
            'CRITICAL': 'bold_red',
        }
    ))
else:
    _handler.setFormatter(logging.Formatter(
        fmt='[%(asctime)s] [%(levelname)s] %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S'
    ))

logger = logging.getLogger(__name__)
logger.setLevel(logging.INFO)
logger.addHandler(_handler)
logger.propagate = False

def acquire_lock():
    """Write the current PID to LOCK_FILE. Returns False if another instance is already running."""
    if os.path.exists(LOCK_FILE):
        try:
            with open(LOCK_FILE) as f:
                pid = int(f.read().strip())
            os.kill(pid, 0)
            return False  # Process is still running
        except (OSError, ValueError):
            pass  # Stale lock file
    with open(LOCK_FILE, 'w') as f:
        f.write(str(os.getpid()))
    return True


def release_lock():
    """Remove the lock file. Called in a finally block so it always runs on exit."""
    try:
        os.remove(LOCK_FILE)
    except OSError:
        pass


def is_array_started():
    """Return True if the Unraid array is started (mdState=STARTED in var.ini)."""
    try:
        with open('/var/local/emhttp/var.ini') as f:
            for line in f:
                line = line.strip()
                if line.startswith('mdState='):
                    value = line.split('=', 1)[1].strip().strip('"\'').upper()
                    return value == 'STARTED'
    except OSError:
        pass
    return False


class ContainerRuntime:
    """Abstract container runtime used by backup/restore operations."""

    def ping(self):
        raise NotImplementedError

    def is_running(self, name):
        raise NotImplementedError

    def stop(self, name):
        raise NotImplementedError

    def start(self, name):
        raise NotImplementedError

    def inspect(self, name):
        raise NotImplementedError


class DockerRuntime(ContainerRuntime):
    """Container runtime backed by the Docker Engine SDK."""

    def __init__(self, host='local', timeout=30):
        self.host = host
        self.timeout = timeout
        self._client = None

    def _get_client(self):
        if self._client is not None:
            try:
                self._client.ping()
                return self._client
            except Exception:
                logger.warning(f"Cached Docker client for '{self.host}' is stale, reconnecting...")
                self._client = None
        try:
            if self.host == 'local':
                logger.debug("Connecting to local Docker engine...")
                self._client = docker.from_env(timeout=self.timeout)
            else:
                remote_url = f'tcp://{self.host}:2375'
                logger.debug(f"Connecting to remote Docker at {remote_url}...")
                self._client = docker.DockerClient(base_url=remote_url, timeout=self.timeout)
        except DockerException as e:
            logger.error(f"Failed to connect to Docker on host '{self.host}': {e}")
            raise
        return self._client

    def ping(self):
        self._get_client().ping()

    def is_running(self, name):
        try:
            container = self._get_client().containers.get(name)
            return container.status == 'running'
        except docker.errors.NotFound:
            logger.warning(f"Container not found: {name}")
            return False

    def stop(self, name):
        try:
            self._get_client().containers.get(name).stop()
            return True
        except Exception as e:
            logger.error(f"Error stopping {name}: {e}")
            return False

    def start(self, name):
        try:
            self._get_client().containers.get(name).start()
            return True
        except Exception as e:
            logger.error(f"Error starting {name}: {e}")
            return False

    def inspect(self, name):
        try:
            return self._get_client().containers.get(name).attrs
        except docker.errors.NotFound:
            logger.warning(f"Container not found: {name}")
            return None
        except docker.errors.APIError as e:
            logger.error(f"Failed to inspect container {name}: {e}")
            return None


class PodmanRuntime(ContainerRuntime):
    """Container runtime backed by the Podman CLI (local or over SSH)."""

    def __init__(self, host='local', ssh_user=None, ssh_key=None, ssh_port=22):
        self.host = host
        self.ssh_user = ssh_user
        self.ssh_key = ssh_key
        self.ssh_port = ssh_port

    def _build_cmd(self, args):
        if self.host == 'local':
            return ['podman', *args]
        cmd = ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5', '-p', str(self.ssh_port)]
        if self.ssh_key:
            cmd.extend(['-i', self.ssh_key])
        target = f'{self.ssh_user}@{self.host}' if self.ssh_user else self.host
        cmd.extend([target, 'podman', *args])
        return cmd

    def _run(self, args, **kwargs):
        return subprocess.run(self._build_cmd(args), **kwargs)

    def ping(self):
        self._run(['version'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    def is_running(self, name):
        try:
            result = self._run(
                ['inspect', name, '--format', '{{.State.Status}}'],
                check=True, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True
            )
            return result.stdout.strip().lower() == 'running'
        except subprocess.CalledProcessError:
            return False

    def stop(self, name):
        try:
            self._run(['stop', name], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            return True
        except subprocess.CalledProcessError as e:
            logger.error(f"Error stopping {name}: {e}")
            return False

    def start(self, name):
        try:
            self._run(['start', name], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            return True
        except subprocess.CalledProcessError as e:
            logger.error(f"Error starting {name}: {e}")
            return False

    def inspect(self, name):
        try:
            result = self._run(
                ['inspect', name],
                check=True, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True
            )
            data = json.loads(result.stdout)
            if isinstance(data, list) and len(data) > 0:
                return data[0]
            return data
        except subprocess.CalledProcessError as e:
            logger.error(f"Failed to inspect container {name}: {e}")
            return None
        except json.JSONDecodeError as e:
            logger.error(f"Failed to parse podman inspect output for {name}: {e}")
            return None


_runtime_cache = {}


def _runtime_key(container):
    """Return a cache key for the runtime described by a container config."""
    host = container.get('host', 'local')
    runtime = container.get('runtime', 'docker').lower()
    ssh_user = container.get('ssh_user') or ''
    ssh_key = container.get('ssh_key') or ''
    ssh_port = container.get('ssh_port', 22)
    return (runtime, host, ssh_user, ssh_key, ssh_port)


def _make_runtime(container):
    """Create a ContainerRuntime instance from a container config."""
    host = container.get('host', 'local')
    runtime_type = container.get('runtime', 'docker').lower()
    if runtime_type == 'podman':
        return PodmanRuntime(
            host=host,
            ssh_user=container.get('ssh_user'),
            ssh_key=container.get('ssh_key'),
            ssh_port=container.get('ssh_port', 22),
        )
    return DockerRuntime(host=host)


def get_runtime(container):
    """Return a cached, pinging ContainerRuntime for the given container config."""
    key = _runtime_key(container)
    if key in _runtime_cache:
        runtime = _runtime_cache[key]
        try:
            runtime.ping()
        except Exception:
            logger.warning(f"Cached runtime for {key} is stale, reconnecting...")
            del _runtime_cache[key]
    if key not in _runtime_cache:
        try:
            runtime = _make_runtime(container)
            runtime.ping()
            _runtime_cache[key] = runtime
        except Exception as e:
            logger.critical(f"Could not create runtime for {key}: {e}")
            return None
    return _runtime_cache[key]


def normalize_group(value):
    """Return (containers, hooks) from either a list or a dict with hooks + containers."""
    if isinstance(value, list):
        return value, {}
    if isinstance(value, dict) and isinstance(value.get('containers'), list):
        return value['containers'], (value.get('hooks') or {})
    if isinstance(value, dict):
        # Legacy/unknown shape: treat keys other than 'hooks' as container entries.
        containers = [v for k, v in value.items() if k != 'hooks' and isinstance(v, dict)]
        return containers, (value.get('hooks') or {})
    raise ValueError("Group must be a list of containers or a mapping with 'containers'.")


def validate_config_structure(config):
    """Perform a quick structural validation of the loaded configuration."""
    if not isinstance(config, dict):
        raise ValueError("Configuration must be a mapping.")
    if not isinstance(config.get('backup_destination'), str) or not config['backup_destination']:
        raise ValueError("backup_destination is required and must be a non-empty string.")
    if not isinstance(config.get('groups'), dict) or not config['groups']:
        raise ValueError("groups must be a non-empty mapping.")
    for group_name, group_value in config['groups'].items():
        try:
            normalize_group(group_value)
        except ValueError as e:
            raise ValueError(f"Group '{group_name}': {e}")


def _log_summary(summary, operation='Backup', dry_run=False):
    """Log a per-container status table and send a single Unraid notification with the overall result.

    summary is a dict mapping (container_id, host) to [container_id, host, status, detail].
    Returns 1 if any container failed, 0 otherwise.
    """
    items   = list(summary.values())
    ok      = sum(1 for _, _, s, _ in items if s == 'ok')
    failed  = sum(1 for _, _, s, _ in items if s == 'failed')
    skipped = sum(1 for _, _, s, _ in items if s == 'skipped')

    logger.info(f"{'- DRY RUN -  ' if dry_run else ''}{operation} summary: {ok} ok, {failed} failed, {skipped} skipped")
    for container_id, host, status, detail in items:
        suffix = f" — {detail}" if detail else ""
        logger.info(f"  {container_id} on {host}: {status.upper()}{suffix}")

    if not dry_run:
        if failed:
            failed_names = ', '.join(f"{c} ({h})" for c, h, s, _ in items if s == 'failed')
            msg = f"{ok} ok, {failed} failed, {skipped} skipped. Failed: {failed_names}"
            notify_host(f"{operation} complete", msg, icon="warning")
        else:
            msg = f"{ok} ok" + (f", {skipped} skipped" if skipped else "")
            notify_host(f"{operation} complete", msg, icon="normal")

    return 1 if failed else 0


def run_hook(script_path, env, label, dry_run=False):
    """Execute a user-supplied hook script. Returns True on success, False on failure.

    In dry-run mode the command is logged but not executed. Non-zero exit code is
    treated as failure and an Unraid notification is sent.
    """
    if not script_path or not isinstance(script_path, str):
        return True

    path = Path(script_path)
    if not path.is_absolute():
        logger.error(f"Hook refused ({label}): path must be absolute: {script_path}")
        notify_host("Hook error", f"{label} path is not absolute: {script_path}", icon="alert", dry_run=dry_run)
        return False

    if dry_run:
        logger.info(f"- DRY RUN - Would run hook: {label} -> {script_path}")
        return True

    if not path.exists():
        logger.error(f"Hook not found ({label}): {script_path}")
        notify_host("Hook error", f"{label} not found: {script_path}", icon="alert", dry_run=dry_run)
        return False

    if not os.access(path, os.X_OK):
        # Try to run via the shell if not executable; still safer to require execute bit.
        logger.warning(f"Hook is not executable ({label}): {script_path}")

    logger.info(f"Running hook: {label} -> {script_path}")
    try:
        subprocess.run([str(path)], check=True, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        logger.info(f"Hook succeeded: {label}")
        return True
    except subprocess.CalledProcessError as e:
        msg = f"{label} failed (exit {e.returncode}): {script_path}"
        if e.stdout:
            msg += f"\n{e.stdout}"
        notify_host("Hook error", msg, icon="alert", dry_run=dry_run)
        logger.error(msg)
        return False
    except Exception as e:
        msg = f"{label} failed: {script_path}: {e}"
        notify_host("Hook error", msg, icon="alert", dry_run=dry_run)
        logger.error(msg)
        return False


def validate_remote_containers(config):
    """Raise ValueError if any remote container references a missing host or lacks SSH credentials."""
    hosts = {h['name']: h for h in config.get('hosts', []) if isinstance(h, dict) and h.get('name')}
    if 'local' not in hosts:
        hosts['local'] = {'name': 'local'}

    for group_name, raw_group in config["groups"].items():
        containers, _ = normalize_group(raw_group)
        for container in containers:
            host_name = container.get("host", "local")
            if host_name == "local":
                continue
            if host_name not in hosts:
                raise ValueError(
                    f"Container '{container['name']}' in group '{group_name}' "
                    f"references unknown host '{host_name}'."
                )
            host_def = hosts[host_name]
            override = container.get("ssh_override", False)
            if isinstance(override, str):
                override = override.lower() in ['yes', 'true', '1']
            has_host_user = bool(host_def.get("ssh_user"))
            has_override_user = override and bool(container.get("ssh_user"))
            if not has_host_user and not has_override_user:
                raise ValueError(
                    f"Container '{container['name']}' in group '{group_name}' "
                    f"on remote host '{host_name}' requires ssh_user on the host or ssh_override."
                )


def resolve_host_defaults(config):
    """Merge host-level defaults (SSH and runtime) into each container.

    Containers keep their own ssh_* values only when ssh_override is enabled.
    The original config is modified in place for convenience.
    """
    hosts = {h['name']: h for h in config.get('hosts', []) if isinstance(h, dict) and h.get('name')}
    if 'local' not in hosts:
        hosts['local'] = {'name': 'local'}

    for _, raw_group in config["groups"].items():
        containers, _ = normalize_group(raw_group)
        for container in containers:
            host_name = container.get("host", "local")
            host_def = hosts.get(host_name, {'name': 'local'})
            override = container.get("ssh_override", False)
            if isinstance(override, str):
                override = override.lower() in ['yes', 'true', '1']
            if host_name != "local" and not override:
                if host_def.get("ssh_user") and not container.get("ssh_user"):
                    container["ssh_user"] = host_def["ssh_user"]
                if host_def.get("ssh_key") and not container.get("ssh_key"):
                    container["ssh_key"] = host_def["ssh_key"]
                if "ssh_port" in host_def and "ssh_port" not in container:
                    container["ssh_port"] = host_def["ssh_port"]
            if host_def.get("runtime") and "runtime" not in container:
                container["runtime"] = host_def["runtime"]


def remote_path_exists(host, ssh_user, ssh_key, ssh_port, remote_path):
    """Return True if remote_path is an existing directory on host (checked via SSH)."""
    check_cmd = ["ssh", "-o", "BatchMode=yes", "-p", str(ssh_port)]
    if ssh_key:
        check_cmd.extend(["-i", ssh_key])
    check_cmd.append(f"{ssh_user}@{host}")
    check_cmd.append(f"test -d {shlex.quote(str(remote_path))}")
    try:
        subprocess.run(check_cmd, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return True
    except subprocess.CalledProcessError:
        return False


def is_container_running(container_id, runtime):
    """Return True if the named container is in the 'running' state."""
    return runtime.is_running(container_id)


def stop_container(container_id, runtime, host, dry_run=False):
    """Stop a running container. Sends an Unraid notification on failure. Returns True on success."""
    logger.info(f"{'- DRY RUN -  ' if dry_run else ''}Stopping container: {container_id} on {host}")
    if dry_run:
        return True
    if runtime.stop(container_id):
        return True
    sub = f"Error stopping {container_id}"
    msg = f"Could not stop {container_id} on {host}"
    notify_host(sub, msg, icon="alert", dry_run=dry_run)
    logger.error(msg)
    return False


def start_container(container_id, runtime, host, dry_run=False):
    """Start a stopped container. Sends an Unraid notification on failure. Returns True on success."""
    logger.info(f"{'- DRY RUN -  ' if dry_run else ''}Starting container: {container_id} on {host}")
    if dry_run:
        return True
    if runtime.start(container_id):
        return True
    sub = f"Error starting {container_id}"
    msg = f"Could not start {container_id} on {host}"
    notify_host(sub, msg, icon="alert", dry_run=dry_run)
    logger.error(msg)
    return False


def rsync_info_flags():
    """Return rsync --info flags appropriate for the output destination.

    When stdout is a terminal, show live per-file progress. When output is
    redirected (e.g., to a log file by the web UI or cron), emit only a concise
    final stats summary to avoid multi-megabyte logs.
    """
    if sys.stdout.isatty():
        return ["--info=progress2"]
    return ["--info=stats2"]


def backup_container_appdata(source_path, dest_root, container_id, host, ssh_user, ssh_key=None, ssh_port=22, dry_run=False, debug=False):
    """Rsync a container's appdata directory to dest_root/container_id.

    For remote hosts, transfers over SSH. Raises FileNotFoundError if the source
    path does not exist. Returns True on success, False on rsync failure.
    """
    source = Path(source_path)
    dest_path = Path(dest_root) / container_id
    logger.info(f"{'- DRY RUN -  ' if dry_run else ''}Backing up data from {host}:{source} to {dest_path}")

    if dry_run:
        logger.info(f"- DRY RUN - Would create directory {dest_path} if it doesn't exist")
        logger.info(f"- DRY RUN - Would rsync from {host}:{source} to {dest_path}")
        return True

    if host == "local":
        if not source.exists():
            raise FileNotFoundError(f"Source path does not exist: {source}")
    else:
        if not remote_path_exists(host, ssh_user, ssh_key, ssh_port, source):
            raise FileNotFoundError(f"Remote source path does not exist: {host}:{source}")

    try:
        dest_path.mkdir(parents=True, exist_ok=True)

        rsync_command = ["rsync", "-a", *rsync_info_flags(), "--delete", "-s"]

        if host != "local":
            ssh_command = f"/usr/bin/ssh -o Compression=no -x -p {ssh_port}"
            if ssh_key:
                ssh_command += f" -i {shlex.quote(ssh_key)}"
            rsync_command.extend(["-e", ssh_command])
            rsync_command.append(f"{ssh_user}@{host}:{source}/")
        else:
            rsync_command.append(f"{source}/")

        rsync_command.append(str(dest_path))

        if debug:
            rsync_command.append("-v")
            logger.debug(f"Running command: {' '.join(rsync_command)}")

        result = subprocess.run(
            rsync_command,
            check=True,
            text=True,
            capture_output=debug
        )
        logger.info(f"Backup complete: {dest_path}")
        if debug:
            if result.stdout:
                logger.debug(f"rsync stdout:\n{result.stdout}")
            if result.stderr:
                logger.debug(f"rsync stderr:\n{result.stderr}")
        return True
    except subprocess.CalledProcessError as e:
        sub = f"Backup error"
        msg = f"rsync failed for {container_id}: {e}"
        notify_host(sub, msg, icon="alert", dry_run=dry_run)
        logger.error(msg)
        if debug and e.stdout:
            logger.debug(f"rsync stdout:\n{e.stdout}")
        if debug and e.stderr:
            logger.debug(f"rsync stderr:\n{e.stderr}")
        return False


def restore_container_appdata(backup_root, container_id, dest_path, host, ssh_user, ssh_key=None, ssh_port=22, dry_run=False, debug=False):
    """Rsync a container's appdata from backup_root/container_id back to dest_path.

    For remote hosts, creates dest_path via SSH before transferring. Raises
    FileNotFoundError if the backup source does not exist. Returns True on success,
    False on rsync failure.
    """
    src_path = Path(backup_root) / container_id
    logger.info(f"{'- DRY RUN -  ' if dry_run else ''}Restoring data to {host}:{dest_path} from {src_path}")

    if dry_run:
        logger.info(f"- DRY RUN - Would rsync from {src_path} to {host}:{dest_path}")
        return True

    if not src_path.exists():
        raise FileNotFoundError(f"Backup path does not exist: {src_path}")

    try:
        if host != "local":
            mkdir_cmd = ["ssh", "-o", "BatchMode=yes", "-p", str(ssh_port)]
            if ssh_key:
                mkdir_cmd.extend(["-i", ssh_key])
            mkdir_cmd.append(f"{ssh_user}@{host}")
            mkdir_cmd.append(f"mkdir -p {shlex.quote(str(dest_path))}")
            subprocess.run(mkdir_cmd, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

        rsync_command = ["rsync", "-a", *rsync_info_flags(), "--delete", "-s"]

        if host != "local":
            ssh_command = f"/usr/bin/ssh -o Compression=no -x -p {ssh_port}"
            if ssh_key:
                ssh_command += f" -i {shlex.quote(ssh_key)}"
            rsync_command.extend(["-e", ssh_command])
            rsync_command.append(f"{str(src_path)}/")
            rsync_command.append(f"{ssh_user}@{host}:{dest_path}/")
        else:
            rsync_command.append(f"{str(src_path)}/")
            rsync_command.append(str(dest_path))

        if debug:
            rsync_command.append("-v")
            logger.debug(f"Running restore command: {' '.join(rsync_command)}")

        result = subprocess.run(
            rsync_command,
            check=True,
            text=True,
            capture_output=debug
        )
        logger.info(f"Restore complete for appdata of {container_id}")
        if debug and result.stdout:
            logger.debug(result.stdout)
        return True
    except subprocess.CalledProcessError as e:
        logger.error(f"rsync failed during restore of {container_id}: {e}")
        if debug and e.stdout:
            logger.debug(e.stdout)
        if debug and e.stderr:
            logger.debug(e.stderr)
        notify_host("Restore error", str(e), icon="alert", dry_run=dry_run)
        return False


def backup_container_json(container_id, backup_root, runtime, host, dry_run=False):
    """Export a container's full config (docker/podman inspect) to backup_root/container_id.json.

    Returns True on success or if the container is not found (JSON is skipped but the
    appdata backup can still proceed). Returns False only on a runtime API/CLI error.
    """
    json_path = Path(backup_root) / f"{container_id}.json"
    logger.info(f"{'- DRY RUN -  ' if dry_run else ''}Saving container config to {json_path}")
    if dry_run:
        logger.info(f"- DRY RUN - Would write JSON config to {json_path}")
        return True
    config_data = runtime.inspect(container_id)
    if config_data is None:
        logger.warning(f"Container {container_id} not found on {host}, skipping JSON backup.")
        return True
    try:
        with json_path.open('w') as f:
            json.dump(config_data, f, indent=2)
        logger.info(f"Saved config for {container_id} to {json_path}")
        return True
    except Exception as e:
        sub = f"Backup error"
        msg = f"Failed to write container config {container_id}: {e}"
        notify_host(sub, msg, icon="alert", dry_run=dry_run)
        logger.error(msg)
        return False


def notify_host(subject, message, icon, dry_run=False):
    """Send a notification via Unraid's notify script. icon should be 'normal', 'warning', or 'alert'."""
    if dry_run:
        logger.info(f"- DRY RUN - Would send notification: [{subject}] {message}")
        return
    try:
        subprocess.run([
            "/usr/local/emhttp/webGui/scripts/notify",
            "-e", "Unraid Appdata Backup Routine",
            "-s", subject,
            "-d", message,
            "-i", icon
        ], check=True)
    except subprocess.CalledProcessError as e:
        logger.error(f"Failed to send notification: {e}")


def main():
    parser = argparse.ArgumentParser(description="Unraid Docker/Podman appdata backup tool")
    parser.add_argument("--group", type=str, help="Name of the group to back up (defaults to all groups)")
    parser.add_argument("--restore", action="store_true", help="Perform a restore operation (defaults to all groups)")
    parser.add_argument("--restore-group", type=str, help="Perform the restore of a specific group")
    parser.add_argument("--restore-container", type=str, help="Perform the restore of a specific container (requires --restore-group or --group)")
    parser.add_argument("--config", type=str, default=os.environ.get('APPDATA_BACKUP_CONFIG', DEFAULT_CONFIG_FILE), help="Path to config.yaml")
    parser.add_argument("--dry-run", action="store_true", help="Show what would happen without making changes")
    parser.add_argument("--debug", action="store_true", help="Enable debug logging")
    args = parser.parse_args()

    operation_label = 'Restore' if args.restore else 'Backup'

    if args.debug:
        logger.setLevel(logging.DEBUG)
        logger.debug("Debug logging enabled.")

    if not acquire_lock():
        logger.critical("Another instance of the backup script is already running. Exiting.")
        notify_host("Backup error", "Another instance is already running.", icon="alert")
        logger.info("RESULT: failed")
        return 1

    try:
        if not is_array_started():
            logger.critical("The Unraid array is not started. Refusing to run.")
            notify_host("Backup error", "The array is not started. Start the array and try again.", icon="alert")
            logger.info("RESULT: failed")
            return 1

        try:
            with open(args.config, 'r') as f:
                config = yaml.safe_load(f)
        except FileNotFoundError:
            notify_host("File not found Error", f"Config file '{args.config}' not found.", icon="alert", dry_run=args.dry_run)
            logger.critical(f"Config file '{args.config}' not found.")
            logger.info("RESULT: failed")
            return 1
        except yaml.YAMLError as e:
            logger.critical(f"Failed to parse YAML config: {e}")
            logger.info("RESULT: failed")
            return 1

        try:
            validate_config_structure(config)
            validate_remote_containers(config)
            resolve_host_defaults(config)
        except ValueError as e:
            notify_host("Config Error", str(e), icon="alert", dry_run=args.dry_run)
            logger.critical(str(e))
            logger.info("RESULT: failed")
            return 1

        if args.group and args.group not in config["groups"]:
            notify_host("Backup error", f"Group '{args.group}' not found in config.", icon="alert", dry_run=args.dry_run)
            logger.error(f"Group '{args.group}' not found in config.")
            logger.info("RESULT: failed")
            return 1

        groups_to_process = (
            {args.group: config["groups"][args.group]} if args.group else config["groups"]
        )
        store_by_group = config.get("store_by_group", False)
        global_hooks = config.get("hooks", {}) if isinstance(config.get("hooks"), dict) else {}
        all_group_names = list(groups_to_process.keys())

        summary = {}

        base_env = {
            **os.environ,
            'APPDATA_OPERATION': operation_label,
            'APPDATA_DRY_RUN': '1' if args.dry_run else '0',
            'APPDATA_GROUPS': ','.join(all_group_names),
            'APPDATA_CONFIG': args.config,
        }

        # --------------------------
        # GLOBAL PRE-OPERATION HOOK
        # --------------------------
        is_restore = args.restore
        global_pre_key = 'pre_restore' if is_restore else 'pre_backup'
        global_post_key = 'post_restore' if is_restore else 'post_backup'
        global_pre_hook = global_hooks.get(global_pre_key)
        global_post_hook = global_hooks.get(global_post_key)

        if not run_hook(global_pre_hook, base_env, f'Global pre-{global_pre_key.replace("_", "-")}', dry_run=args.dry_run):
            logger.info("RESULT: failed")
            return 1

        # --------------------------
        # RESTORE BACKUP TO GROUP / GROUP + CONTAINER
        # --------------------------
        if args.restore:
            if args.restore_container and not args.restore_group and not args.group:
                logger.error("Must specify --restore-group or --group if using --restore-container")
                logger.info("RESULT: failed")
                return 1

            effective_restore_group = args.restore_group or args.group
            if effective_restore_group and effective_restore_group not in config["groups"]:
                logger.error(f"Group '{effective_restore_group}' not found in config.")
                logger.info("RESULT: failed")
                return 1
            restore_groups = (
                {effective_restore_group: config["groups"][effective_restore_group]}
                if effective_restore_group else config["groups"]
            )

            stopped_containers = set()
            container_matched = False

            for group_name, raw_group in restore_groups.items():
                containers, group_hooks = normalize_group(raw_group)
                group_env = {
                    **base_env,
                    'APPDATA_GROUP': group_name,
                    'APPDATA_BACKUP_ROOT': str(Path(config["backup_destination"]) / group_name if store_by_group else Path(config["backup_destination"])),
                }

                group_pre_key = 'pre_restore'
                group_post_key = 'post_restore'
                group_pre_hook = group_hooks.get(group_pre_key)
                group_post_hook = group_hooks.get(group_post_key)

                if not run_hook(group_pre_hook, group_env, f'Group {group_name} pre-{group_pre_key.replace("_", "-")}', dry_run=args.dry_run):
                    logger.info("RESULT: failed")
                    return 1

                backup_root = Path(group_env['APPDATA_BACKUP_ROOT'])
                logger.info(f"Restoring group: {group_name}")

                for container in containers:
                    container_id = container["name"]
                    host = container.get("host", "local")
                    ssh_user = container.get("ssh_user")
                    ssh_key = container.get("ssh_key")
                    ssh_port = container.get("ssh_port", 22)
                    appdata_path = container.get("appdata_path")
                    runtime = get_runtime(container)
                    if runtime is None:
                        logger.error(f"Container {container_id} failed due to runtime connection issue on {host}")
                        summary[(container_id, host)] = [container_id, host, 'failed', 'Runtime connection failed']
                        continue
                    if args.restore_container and container_id != args.restore_container:
                        continue

                    container_matched = True
                    status = 'ok'
                    detail = ''

                    was_running = is_container_running(container_id, runtime)
                    stopped = False
                    if was_running:
                        stopped = stop_container(container_id, runtime, host, dry_run=args.dry_run)

                    if stopped:
                        stopped_containers.add((container_id, host))
                    elif was_running:
                        status = 'failed'
                        detail = 'stop failed'
                        logger.error(f"Could not stop {container_id} on {host}; skipping restore")

                    if status == 'ok' and appdata_path:
                        try:
                            if not restore_container_appdata(
                                backup_root, container_id, appdata_path, host,
                                ssh_user, ssh_key, ssh_port,
                                dry_run=args.dry_run, debug=args.debug
                            ):
                                status = 'failed'
                                detail = 'appdata restore failed'
                        except Exception as e:
                            status = 'failed'
                            detail = str(e)
                            logger.error(f"Appdata restore failed for {container_id}: {e}")
                            notify_host("Restore error", str(e), icon="alert", dry_run=args.dry_run)

                    if (container_id, host) in stopped_containers:
                        if not start_container(container_id, runtime, host, dry_run=args.dry_run):
                            status = 'failed'
                            detail = 'start failed'

                    summary[(container_id, host)] = [container_id, host, status, detail]

            if args.restore_container and not container_matched:
                logger.warning(f"No container named '{args.restore_container}' found in the specified group(s).")

            if not run_hook(group_post_hook, group_env, f'Group {group_name} post-{group_post_key.replace("_", "-")}', dry_run=args.dry_run):
                logger.info("RESULT: failed")
                return 1

            rc = _log_summary(summary, operation='Restore', dry_run=args.dry_run)
            logger.info(f"RESULT: {'failed' if rc != 0 else 'success'}")
            return rc

        # --------------------------
        # PERFORM A BACKUP IF --restore IS NOT PASSED
        # --------------------------
        for group_name, raw_group in groups_to_process.items():
            containers, group_hooks = normalize_group(raw_group)
            group_env = {
                **base_env,
                'APPDATA_GROUP': group_name,
                'APPDATA_BACKUP_ROOT': str(Path(config["backup_destination"]) / group_name if store_by_group else Path(config["backup_destination"])),
            }

            group_pre_key = 'pre_backup'
            group_post_key = 'post_backup'
            group_pre_hook = group_hooks.get(group_pre_key)
            group_post_hook = group_hooks.get(group_post_key)

            if not run_hook(group_pre_hook, group_env, f'Group {group_name} pre-{group_pre_key.replace("_", "-")}', dry_run=args.dry_run):
                logger.info("RESULT: failed")
                return 1

            backup_root = Path(group_env['APPDATA_BACKUP_ROOT'])
            if args.dry_run:
                logger.info(f"- DRY RUN - Would create directory {backup_root} if it doesn't exist")
            else:
                backup_root.mkdir(parents=True, exist_ok=True)

            logger.info(f"{'- DRY RUN -  ' if args.dry_run else ''}Processing group: {group_name}")
            containers_to_restart = []
            stop_failed = set()

            # Step 1: Stop containers marked for restart
            for container in containers:
                container_id = container["name"]
                host = container.get("host", "local")
                runtime = get_runtime(container)
                if runtime is None:
                    logger.error(f"Skipping container {container_id} due to runtime connection issue on {host}")
                    stop_failed.add(container_id)
                    continue
                restart_value = container.get("restart", False)
                should_restart = str(restart_value).lower() == "yes" if isinstance(restart_value, str) else bool(restart_value)

                if should_restart and is_container_running(container_id, runtime):
                    if stop_container(container_id, runtime, host, dry_run=args.dry_run):
                        containers_to_restart.append(container_id)
                    else:
                        logger.error(f"Could not stop {container_id} on {host}; it will be skipped")
                        stop_failed.add(container_id)
                elif should_restart:
                    logger.info(f"{'- DRY RUN -  ' if args.dry_run else ''}{container_id} was not running on {host}, skipping stop.")
                else:
                    logger.info(f"{'- DRY RUN -  ' if args.dry_run else ''}Skipping stop for {container_id} on {host} (restart=no).")

            # Step 2: Perform backup
            for container in containers:
                container_id = container["name"]
                host = container.get("host", "local")
                ssh_user = container.get("ssh_user")
                ssh_key = container.get("ssh_key")
                ssh_port = container.get("ssh_port", 22)
                runtime = get_runtime(container)
                if runtime is None:
                    logger.error(f"Container {container_id} failed due to runtime connection issue on {host}")
                    summary[(container_id, host)] = [container_id, host, 'failed', 'Runtime connection failed']
                    continue

                if container_id in stop_failed:
                    summary[(container_id, host)] = [container_id, host, 'failed', 'stop failed']
                    continue

                status = 'ok'
                detail = ''
                source_path = container.get("appdata_path")

                if not backup_container_json(container_id, backup_root, runtime, host, dry_run=args.dry_run):
                    status = 'failed'
                    detail = 'JSON backup failed'

                if not source_path:
                    logger.info(f"{'- DRY RUN -  ' if args.dry_run else ''}Skipping data backup for {container_id} (no path).")
                    summary[(container_id, host)] = [container_id, host, status, detail or 'no appdata_path']
                    continue

                try:
                    if not backup_container_appdata(
                        source_path, backup_root, container_id, host,
                        ssh_user, ssh_key, ssh_port,
                        dry_run=args.dry_run, debug=args.debug
                    ):
                        if status != 'failed':
                            status = 'failed'
                            detail = 'appdata backup failed'
                except Exception as e:
                    notify_host(f"Backup error for {container_id}", str(e), icon="alert", dry_run=args.dry_run)
                    logger.error(f"{container_id} backup failed: {e}")
                    status = 'failed'
                    detail = str(e)

                summary[(container_id, host)] = [container_id, host, status, detail]

            # Step 3: Start previously stopped containers
            for container_id in reversed(containers_to_restart):
                container_cfg = next((c for c in containers if c["name"] == container_id), {})
                host = container_cfg.get("host", "local")
                restart_runtime = get_runtime(container_cfg)
                if restart_runtime is None:
                    logger.error(f"Skipping restart of container {container_id} due to runtime connection issue on {host}")
                    if (container_id, host) in summary:
                        summary[(container_id, host)][2] = 'failed'
                        summary[(container_id, host)][3] = 'restart connection failed'
                    continue
                delay = container_cfg.get("start_delay", 0)
                if delay > 0:
                    logger.info(f"Waiting {delay} seconds before starting {container_id} on {host}")
                    if not args.dry_run:
                        time.sleep(delay)
                if not start_container(container_id, restart_runtime, host, dry_run=args.dry_run):
                    if (container_id, host) in summary:
                        summary[(container_id, host)][2] = 'failed'
                        summary[(container_id, host)][3] = 'start failed'

            if not run_hook(group_post_hook, group_env, f'Group {group_name} post-{group_post_key.replace("_", "-")}', dry_run=args.dry_run):
                logger.info("RESULT: failed")
                return 1

        if not run_hook(global_post_hook, base_env, f'Global post-{global_post_key.replace("_", "-")}', dry_run=args.dry_run):
            logger.info("RESULT: failed")
            return 1

        rc = _log_summary(summary, operation='Backup', dry_run=args.dry_run)
        logger.info(f"RESULT: {'failed' if rc != 0 else 'success'}")
        return rc

    finally:
        release_lock()


if __name__ == '__main__':
    sys.exit(main())
