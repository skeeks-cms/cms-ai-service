"""Restart the local SkeekS AI pilot service with the same executable, arguments, cwd and environment.
Prints only PIDs, statuses and variable names; never values or keys."""
import json
import socket
import subprocess
import sys
import time
import urllib.request
import urllib.error

import psutil

PORT = 18764
ENTRY = r"C:\SkeekS\dev\php\vendor\skeeks\cms-ai-service\pilot\server.mjs"


def listener():
    for c in psutil.net_connections(kind="tcp"):
        if c.status == psutil.CONN_LISTEN and c.laddr and c.laddr.port == PORT and c.laddr.ip in ("127.0.0.1", "0.0.0.0"):
            return c.pid
    return None


pid = listener()
if not pid:
    print("port free: nothing to restart, environment of the previous process is unavailable")
    sys.exit(2)
proc = psutil.Process(pid)
exe, cmd, cwd, env = proc.exe(), proc.cmdline(), proc.cwd(), proc.environ()
if not exe.lower().endswith("node.exe") or not any(a.lower() == ENTRY.lower() for a in cmd):
    print("port belongs to another process:", proc.name())
    sys.exit(3)
print("old pid:", pid, "| SKEEKS_AI_* vars:", sorted(k for k in env if k.startswith("SKEEKS_AI_")))

check = subprocess.run([exe, "--check", ENTRY], capture_output=True, text=True)
if check.returncode != 0:
    print("syntax check failed:", check.stderr[:500])
    sys.exit(4)

proc.terminate()
try:
    proc.wait(10)
except psutil.TimeoutExpired:
    proc.kill()
    proc.wait(5)

subprocess.Popen(cmd, cwd=cwd, env=env, creationflags=subprocess.CREATE_NO_WINDOW,
                 stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

new_pid = None
for _ in range(60):
    time.sleep(0.5)
    new_pid = listener()
    if new_pid and new_pid != pid:
        break
print("new pid:", new_pid)
if not new_pid or new_pid == pid:
    sys.exit(5)

key_file = env.get("SKEEKS_AI_STORE_KEY_FILE", "")
with open(key_file, encoding="utf-8") as f:
    key = f.read().strip()
req = urllib.request.Request(f"http://127.0.0.1:{PORT}/agent-turn", data=b"{}", method="POST",
                             headers={"content-type": "application/json", "x-skeeks-ai-bridge": key})
try:
    with urllib.request.urlopen(req, timeout=10) as r:
        status, body = r.status, r.read().decode("utf-8", "replace")
except urllib.error.HTTPError as e:
    status, body = e.code, e.read().decode("utf-8", "replace")
del key
message = ""
try:
    message = json.loads(body).get("error", "")
except ValueError:
    pass
sys.stdout.reconfigure(encoding="utf-8")
print("probe:", status, message)
