import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, copyFile, symlink, writeFile, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';

test('restarts failed and cleanly exited workers while keeping the scheduler alive', { timeout: 15000 }, async () => {
  const fixture = await mkdtemp(join(tmpdir(), 'maildesk-worker-test-'));
  let child, output = '', stopped;
  try {
    await mkdir(join(fixture, 'bin'));
    await copyFile(new URL('../../start.sh', import.meta.url), join(fixture, 'start.sh'));
    await symlink(fileURLToPath(new URL('../../node_modules', import.meta.url)), join(fixture, 'node_modules'));
    await writeFile(join(fixture, 'bin/php'), `#!/bin/sh
if [ "$2" = "queue:work" ]; then
  count=0
  if [ -f worker-count ]; then read -r count < worker-count; fi
  count=$((count + 1))
  echo "$count" > worker-count
  case "$count" in 1) exit 1;; 2) exit 12;; 3) exit 0;; esac
else
  echo scheduler-started >> scheduler-count
fi
exec sleep 60
`, { mode: 0o755 });
    child = spawn('bash', ['start.sh'], { cwd: fixture, detached: true, env: { ...process.env, PATH: `${join(fixture, 'bin')}:${process.env.PATH}` } });
    child.stdout.on('data', chunk => { output += chunk; });
    child.stderr.on('data', chunk => { output += chunk; });
    stopped = new Promise((resolve, reject) => { child.once('close', resolve); child.once('error', reject); });
    let count = '';
    for (let attempt = 0; attempt < 140; attempt++) {
      try { count = (await readFile(join(fixture, 'worker-count'), 'utf8')).trim(); }
      catch (error) { if (error.code !== 'ENOENT') throw error; }
      if (count === '4' || child.exitCode !== null) break;
      await delay(50);
    }
    assert.equal(count, '4', `worker did not restart: ${output}`);
    assert.equal(child.exitCode, null);
    assert.equal((await readFile(join(fixture, 'scheduler-count'), 'utf8')).trim(), 'scheduler-started');
  } finally {
    if (child?.pid) {
      try { process.kill(-child.pid, 'SIGTERM'); }
      catch (error) { if (error.code !== 'ESRCH') throw error; }
      await stopped;
    }
    await rm(fixture, { recursive: true, force: true });
  }
});
