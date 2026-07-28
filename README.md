# AppdataSync

An Unraid plugin that backs up, restores, and syncs Docker container appdata between local and remote hosts through the Unraid web UI. 

---

## Features

- **Web UI configuration editor** for backup groups, containers, paths, and SSH settings
- **Manual backup and restore** with group or container granularity
- **Dry-run and debug modes** for safe testing
- **Scheduled backups** via Unraid's cron system
- **Local and remote host support** using `rsync` over SSH
- **Live log tail** while jobs run
- **Rotating run history** keeps the last 5 logs and results
- **Container config export** (`docker inspect` JSON) alongside appdata

---

## How it differs from CA Appdata Backup / Restore

AppdataSync is a narrower alternative that exists to scratch a different itch. 

The main differences:

- **Remote source hosts.** CA backs up containers running on the local Unraid box.
  AppdataSync can also back up appdata from containers running on remote Docker
  hosts, pulled over SSH via reusable host profiles (`rsync` over SSH). This is
  the primary reason AppdataSync exists.
- **Mirror vs. snapshots.** CA keeps dated, full tar snapshots with retention
  by age, giving you point-in-time history to roll back to. AppdataSync keeps a
  more live mirror (`--delete`) of each container's appdata plus an exported
  `docker inspect` JSON config , i.e. the current state, not a history of past
  runs. The rotating "last 5" history here refers to run logs/results, not backup
  snapshots. If you need to restore a container to how it looked three days ago,
  use CA; if you want a current, browsable copy of appdata, AppdataSync
  fits better.
- **Group + hook orchestration.** AppdataSync organizes containers into groups
  with global (`pre_run` / `post_run`) and per-group (`pre_group` / `post_group`)
  hooks that abort the run on non-zero exit. CA has its own per-container settings,
  exclusions, and PreRun/PreBackup/PostBackup/PostRun scripts, a different, more
  per-container model.
- **Scope.** AppdataSync handles Docker appdata only. CA additionally backs
  up your USB flash drive and VM/libvirt metadata.
- **Backend.** AppdataSync is Python-based (see the Python requirement above);
  CA is PHP/bash. Pick whichever matches what you already have provisioned.

If you only back up local containers and want dated snapshots, CA is the safer
choice. If you have containers spread across multiple hosts, want a current
remote-mirror of their appdata, or prefer group/hook-driven orchestration,
AppdataSync is worth a look.

---

## Installation

1. In the Unraid UI go to **Plugins > Install Plugin**
2. Paste the `.plg` URL from the [latest release](https://raw.githubusercontent.com/wandercone/appdatasync/main/plugin/appdatasync.plg)
3. Click **Install**

After installation the plugin is available at **Tools > AppdataSync**.

---

## Requirements

- Unraid 7.0.0 or later
- Docker Engine on target hosts
- `rsync` and `ssh` available on local and remote systems
- Python packages: `docker`, `pyyaml` (`colorlog` is optional, for colored terminal output)

### Python 3 (required as Unraid ships without it)

Unraid does **not** include Python by default, and this plugin's install script
will only `pip install` the dependencies above if a `python3` interpreter is
already present (otherwise it prints a warning and the backend won't run). You
must install a Python 3 provider plugin **before** installing AppdataSync.

The recommended provider is desertwitch's **python-unRAID** (`dwpython`), which
installs Python 3, pip, and setuptools as proper Unraid packages:

1. In the Unraid UI go to **Plugins > Install Plugin**
2. Paste the dwpython plugin URL:
   `https://raw.githubusercontent.com/desertwitch/python-unRAID/main/plugin/dwpython.plg`
3. Click **Install**, then accept the default backend (Python 3.11.x) or pick a
   newer one (3.12 / 3.13 / 3.14) from its settings page

AppdataSync is developed and tested against the Python versions shipped by
dwpython (3.11+). Older interpreters may work but are not supported.

---

## Usage

1. Open **Tools > AppdataSync**
2. Configure your backup destination, groups, and containers in the web UI
3. Click **Save Configuration**
4. Run a **Dry Run** first to preview what will happen
5. Run **Backup All** or **Backup Group** to back up appdata
6. Use **Restore Group** or **Restore Container** to restore from a previous backup

---

## Configuration

All container and host settings are managed through the web UI and stored in:

```text
/boot/config/plugins/appdatasync/config.yaml
```

SSH credentials live in **host profiles** under `hosts:` and are inherited by any
container that references the profile by `host:` name. The `local` host is always
available and carries no SSH settings. To give a single container different SSH
credentials from its host profile, set `ssh_override: yes` on the container and
provide `ssh_user`, `ssh_key`, and `ssh_port` there.

Example `config.yaml`:

```yaml
backup_destination: /mnt/user/backup/appdata
store_by_group: yes

hosts:
  - name: local
  - name: my-server
    ssh_user: userName
    ssh_key: /mnt/user/system/keys/ssh_key
    ssh_port: 22

groups:
  group-1:
    - name: container-a          # local container
      host: local
      appdata_path: /mnt/user/appdata/container-a
      restart: yes
      start_delay: 10
    - name: container-c          # remote container, inherits my-server SSH
      host: my-server
      appdata_path: /docker/container-c
      restart: yes
```

Optional **hooks** (absolute script paths; a non-zero exit aborts the run and marks
it failed) can be set globally as `hooks.pre_run` / `hooks.post_run`, and per group
as `hooks.pre_group` / `hooks.post_group`.

---

## Scheduling

Enable scheduled backups from the plugin UI:

- Choose frequency: daily, weekly, monthly, or custom cron
- Select groups to back up (leave blank for all groups)
- Optionally run scheduled backups in dry-run mode

The schedule writes a plugin-owned cron file and syncs it via `/usr/local/sbin/update_cron`.
Scheduled and manual run logs are kept under `/boot/config/plugins/appdatasync/logs/`, with the last 5 runs available in the **Recent Runs** section.

---

## AI / LLM Disclosure

The core `backup.py` utility was written and used by me over the last couple years. The Unraid web UI portions of this plugin were developed with assistance from large-language-model coding tools, specifically **Kimi K2.7** (accessed via the Claude Code CLI, model `kimi-k2.7-code:cloud`) and **GLM-5.2** (accessed via the Claude Code CLI, model `GLM-5.2:cloud`).

This work is shared partly as a learning exercise in AI/LLM-assisted development, prompt engineering, and practical tooling integration. The generated code was reviewed, tested, and refined by the project author, but contributors and users are encouraged to audit it as they would any AI-assisted codebase.