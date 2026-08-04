# QUALI-D Remote Agent — FreePBX Module

Allows FreePBX customers to connect their on-premise PBX to the QUALI-D cloud relay so agents can receive calls from anywhere — including countries where SIP port 5060 is blocked.

---

## Install on Customer FreePBX (One Command)

```bash
fwconsole ma downloadinstall https://github.com/your-org/qualid-freepbx/releases/latest/download/qualid_remote.tar.gz
fwconsole reload
```

That's it. The module appears under **Admin → QUALI-D Remote Agent**.

---

## What the Admin Does

1. Opens **Admin → QUALI-D Remote Agent**
2. Pastes their QUALI-D API key
3. Clicks **Connect** — the module:
   - Calls the QUALI-D provision API
   - Gets back: SIP domain, trunk credentials, TURN server
   - Writes `/etc/asterisk/pjsip_qualid.conf` (WSS trunk)
   - Writes `/etc/asterisk/extensions_qualid.conf` (dialplan)
   - Reloads Asterisk
4. Clicks **Provision** next to each remote agent to assign them a SIP account
5. Done — agents install the QUALI-D app and are ready

---

## Build a New Release

```bash
./build.sh             # creates qualid_remote-1.0.0.tar.gz
./build.sh --publish   # creates it + makes a GitHub release (needs gh CLI)
```

---

## File Structure

```
FreePBX Module/
├── build.sh                          ← packaging script
├── README.md
└── qualid_remote/                    ← the actual FreePBX module
    ├── module.xml                    ← metadata (name, version, category)
    ├── page.qualid_remote.php        ← admin UI (the branded page)
    ├── functions.inc.php             ← all logic: API calls, config write
    ├── install.php                   ← runs on module install
    ├── uninstall.php                 ← cleans up on uninstall
    ├── assets/
    │   ├── css/qualid_remote.css     ← QUALI-D branded styles
    │   └── js/qualid_remote.js       ← AJAX, agent management JS
    └── etc/asterisk/
        ├── pjsip_qualid.conf.template
        └── extensions_qualid.conf.template
```
