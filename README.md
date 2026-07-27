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
- **Container config export** (`docker inspect` JSON) alongside appdata

---

## Installation

1. In the Unraid UI go to **Plugins > Install Plugin**
2. Paste the `.plg` URL from the [latest release](https://raw.githubusercontent.com/wandercone/appdatasync/main/plugin/appdatasync.plg)
3. Click **Install**

After installation the plugin is available at **Tools > AppdataSync**.

---

## Requirements

- Unraid 7.0.0 or later
- Python 3.7 or later
- Docker Engine on target hosts
- `rsync` and `ssh` available on local and remote systems
- Python packages: `colorlog`, `docker`, `pyyaml`, `schema`

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

Example `config.yaml`:

```yaml
backup_destination: /mnt/user/backup/appdata
store_by_group: yes
groups:
  group-1:
    - name: container-a
      host: local
      appdata_path: /mnt/user/appdata/container-a
      restart: yes
      start_delay: 10
```

Remote containers add `ssh_user`, `ssh_key`, and `ssh_port`.

---

## Scheduling

Enable scheduled backups from the plugin UI:

- Choose frequency: daily, weekly, monthly, or custom cron
- Select groups to back up (leave blank for all groups)
- Optionally run scheduled backups in dry-run mode

The schedule writes a plugin-owned cron file and syncs it via `/usr/local/sbin/update_cron`.

---

## AI / LLM Disclosure

The core `backup.py` utility was written and used by me over the last couple years. The Unraid web UI portions of this plugin were developed with assistance from large-language-model coding tools, specifically **Kimi K2.7** (accessed via the Claude Code CLI, model `kimi-k2.7-code:cloud`) and **GLM-5.2** (accessed via the Claude Code CLI, model `GLM-5.2:cloud`).

This work is shared partly as a learning exercise in AI/LLM-assisted development, prompt engineering, and practical tooling integration. The generated code was reviewed, tested, and refined by the project author, but contributors and users are encouraged to audit it as they would any AI-assisted codebase.