#!/usr/bin/env python3
"""
Unit tests for pi_daemon.py.

Run from this directory: python3 test_pi_daemon.py
(or: python3 -m unittest test_pi_daemon)

pi_daemon.py is only ever executed on Linux (the Pi), and uses os.geteuid()
at import time to decide whether to prefix the FM binary with sudo. Stub it
out here so the module can be imported and unit-tested from any OS.
"""
import json
import os
import sys
import tempfile
import time
import unittest
from unittest import mock

if not hasattr(os, 'geteuid'):
    os.geteuid = lambda: 0

sys.path.insert(0, os.path.dirname(os.path.realpath(__file__)))
import pi_daemon


class ShoutoutNameTests(unittest.TestCase):
    """Regression coverage for the crash fixed in _shoutout_name:
    item.get('requested_by_name', '').strip() raised AttributeError whenever
    the server sent an explicit JSON null (the normal case for auto-filled,
    unrequested songs), permanently killing the scheduler thread."""

    def test_missing_key_returns_empty(self):
        self.assertEqual(pi_daemon._shoutout_name({}), '')

    def test_null_value_returns_empty(self):
        self.assertEqual(pi_daemon._shoutout_name({'requested_by_name': None}), '')

    def test_blank_value_returns_empty(self):
        self.assertEqual(pi_daemon._shoutout_name({'requested_by_name': '   '}), '')

    def test_valid_name_is_trimmed(self):
        self.assertEqual(pi_daemon._shoutout_name({'requested_by_name': '  Alex  '}), 'Alex')


class LocalConfigRoundTripTests(unittest.TestCase):
    def setUp(self):
        self.tmpdir = tempfile.TemporaryDirectory()
        self._orig_config_path = pi_daemon.CONFIG_PATH
        pi_daemon.CONFIG_PATH = os.path.join(self.tmpdir.name, 'config.json')

    def tearDown(self):
        pi_daemon.CONFIG_PATH = self._orig_config_path
        self.tmpdir.cleanup()

    def test_load_missing_file_returns_defaults(self):
        cfg = pi_daemon.load_local_config()
        self.assertEqual(cfg, pi_daemon.LOCAL_CONFIG_KEYS)

    def test_save_then_load_round_trips(self):
        cfg = pi_daemon.load_local_config()
        cfg['api_key'] = 'abc123'
        cfg['freq'] = 101.1
        pi_daemon.save_local_config(cfg)

        reloaded = pi_daemon.load_local_config()
        self.assertEqual(reloaded['api_key'], 'abc123')
        self.assertEqual(reloaded['freq'], 101.1)

    def test_load_tolerates_partial_json(self):
        with open(pi_daemon.CONFIG_PATH, 'w') as f:
            json.dump({'api_key': 'xyz'}, f)

        cfg = pi_daemon.load_local_config()
        self.assertEqual(cfg['api_key'], 'xyz')
        self.assertEqual(cfg['server_url'], pi_daemon.LOCAL_CONFIG_KEYS['server_url'])


class MediaPathSafetyTests(unittest.TestCase):
    def setUp(self):
        self.tmpdir = tempfile.TemporaryDirectory()
        self.cfg = {
            'song_dir': os.path.join(self.tmpdir.name, 'songs'),
            'commercial_dir': os.path.join(self.tmpdir.name, 'commercials'),
            'sound_byte_dir': os.path.join(self.tmpdir.name, 'sound-bytes'),
        }
        for path in self.cfg.values():
            os.makedirs(path, exist_ok=True)

    def tearDown(self):
        self.tmpdir.cleanup()

    def test_safe_filename_resolves_inside_media_dir(self):
        path = pi_daemon.media_path(self.cfg, 'song', 'track.wav')
        self.assertEqual(path, os.path.realpath(os.path.join(self.cfg['song_dir'], 'track.wav')))

    def test_rejects_parent_directory(self):
        with self.assertRaises(ValueError):
            pi_daemon.media_path(self.cfg, 'song', '../evil.wav')

    def test_rejects_absolute_path(self):
        with self.assertRaises(ValueError):
            pi_daemon.media_path(self.cfg, 'song', os.path.abspath('evil.wav'))

    def test_rejects_nested_path(self):
        with self.assertRaises(ValueError):
            pi_daemon.media_path(self.cfg, 'song', 'nested/evil.wav')

    def test_rejects_backslash_path(self):
        with self.assertRaises(ValueError):
            pi_daemon.media_path(self.cfg, 'song', r'nested\evil.wav')


class PendingMediaSafetyTests(unittest.TestCase):
    def setUp(self):
        self.tmpdir = tempfile.TemporaryDirectory()
        self.cfg = {
            'api_key': 'token',
            'server_url': 'https://fmplaylist.test',
            'song_dir': self.tmpdir.name,
            'commercial_dir': os.path.join(self.tmpdir.name, 'commercials'),
            'sound_byte_dir': os.path.join(self.tmpdir.name, 'sound-bytes'),
            'verify_ssl': True,
        }

    def tearDown(self):
        self.tmpdir.cleanup()

    def test_pending_download_skips_unsafe_filename(self):
        with mock.patch.object(pi_daemon, 'api_post') as api_post:
            pi_daemon._process_pending_downloads(self.cfg, {
                'pending_downloads': [{
                    'type': 'song',
                    'item_id': 1,
                    'filename': '../evil.wav',
                    'download_url': 'https://example.test/evil.wav',
                }],
            })

        api_post.assert_not_called()

    def test_pending_delete_skips_unsafe_filename(self):
        with mock.patch.object(pi_daemon, 'api_post') as api_post:
            pi_daemon._process_pending_deletes(self.cfg, {
                'pending_deletes': [{
                    'type': 'song',
                    'item_id': 1,
                    'filename': '../evil.wav',
                }],
            })

        api_post.assert_not_called()


class SourceHashTests(unittest.TestCase):
    def setUp(self):
        self.tmpdir = tempfile.TemporaryDirectory()
        self.src = os.path.join(self.tmpdir.name, 'src')
        self.state = os.path.join(self.tmpdir.name, '.install-state')
        os.makedirs(self.src, exist_ok=True)
        os.makedirs(self.state, exist_ok=True)
        self._orig_script_dir = pi_daemon.SCRIPT_DIR
        self._orig_manifest = pi_daemon.SOURCE_MANIFEST_PATH
        pi_daemon.SCRIPT_DIR = self.src
        pi_daemon.SOURCE_MANIFEST_PATH = os.path.join(self.state, 'source-files.txt')

    def tearDown(self):
        pi_daemon.SCRIPT_DIR = self._orig_script_dir
        pi_daemon.SOURCE_MANIFEST_PATH = self._orig_manifest
        self.tmpdir.cleanup()

    def test_hash_uses_manifest_instead_of_extra_local_source_files(self):
        with open(os.path.join(self.src, 'pi_daemon.py'), 'w') as f:
            f.write('current')
        with open(os.path.join(self.src, 'old_helper.py'), 'w') as f:
            f.write('stale')
        with open(pi_daemon.SOURCE_MANIFEST_PATH, 'w') as f:
            f.write('pi_daemon.py\n')

        first = pi_daemon._own_daemon_hash()
        os.remove(os.path.join(self.src, 'old_helper.py'))
        second = pi_daemon._own_daemon_hash()

        self.assertEqual(first, second)


class UpdateInstallerTests(unittest.TestCase):
    def tearDown(self):
        pi_daemon._update_in_progress = False

    def test_update_downloads_setup_script_and_runs_installer_with_token(self):
        response = mock.Mock()
        response.__enter__ = mock.Mock(return_value=response)
        response.__exit__ = mock.Mock(return_value=False)
        response.read.return_value = b'#!/usr/bin/env bash\n'

        completed = mock.Mock(returncode=0)

        with mock.patch('urllib.request.urlopen', return_value=response) as urlopen, \
                mock.patch('builtins.open', mock.mock_open()) as opened, \
                mock.patch('os.chmod') as chmod, \
                mock.patch('os.path.exists', return_value=False), \
                mock.patch('subprocess.run', return_value=completed) as run, \
                mock.patch.object(pi_daemon, '_stop_fm') as stop_fm, \
                mock.patch.object(pi_daemon.os, '_exit', side_effect=SystemExit) as exit_call:
            pi_daemon._apply_daemon_update({
                'server_url': 'https://fmplaylist.com',
                'api_key': 'token123',
            })

        # The daemon must NOT kill itself any more: the installer schedules its
        # own detached restart plus a health check that can roll back. Exiting
        # here would race that and give up the process before it could report.
        exit_call.assert_not_called()
        stop_fm.assert_not_called()

        request = urlopen.call_args.args[0]
        self.assertEqual(request.full_url, 'https://fmplaylist.com/pi/setup.sh')
        opened.assert_called()
        chmod.assert_called()
        run.assert_called_once()
        self.assertEqual(run.call_args.args[0], ['bash', mock.ANY, 'token123'])


class WifiProfileStoreTests(unittest.TestCase):
    """The on-disk profile cache is what lets the Pi rejoin a fallback network
    after a reboot with no internet — if it can't be read back, the whole
    feature silently degrades to 'no wifi'."""

    def setUp(self):
        self.tmpdir = tempfile.TemporaryDirectory()
        self._orig = pi_daemon.WIFI_PROFILES_PATH
        pi_daemon.WIFI_PROFILES_PATH = os.path.join(
            self.tmpdir.name, 'etc', 'wifi-networks.json')

    def tearDown(self):
        pi_daemon.WIFI_PROFILES_PATH = self._orig
        self.tmpdir.cleanup()

    def test_missing_file_returns_empty(self):
        got = pi_daemon.load_wifi_profiles()
        self.assertEqual(got, {'rev': '', 'profiles': []})

    def test_round_trip_preserves_order(self):
        profiles = [
            {'ssid': 'Primary', 'password': 'p1', 'priority': 0},
            {'ssid': 'Backup', 'password': '', 'priority': 1},
        ]
        pi_daemon.save_wifi_profiles(profiles, 'abc123')

        got = pi_daemon.load_wifi_profiles()
        self.assertEqual(got['rev'], 'abc123')
        self.assertEqual([p['ssid'] for p in got['profiles']], ['Primary', 'Backup'])

    def test_creates_parent_directory(self):
        pi_daemon.save_wifi_profiles([{'ssid': 'X'}], 'r1')
        self.assertTrue(os.path.exists(pi_daemon.WIFI_PROFILES_PATH))

    def test_corrupt_file_does_not_raise(self):
        os.makedirs(os.path.dirname(pi_daemon.WIFI_PROFILES_PATH), exist_ok=True)
        with open(pi_daemon.WIFI_PROFILES_PATH, 'w') as f:
            f.write('{not json')
        self.assertEqual(pi_daemon.load_wifi_profiles()['profiles'], [])

    def test_entries_without_ssid_are_dropped(self):
        pi_daemon.save_wifi_profiles(
            [{'ssid': 'Good'}, {'password': 'orphan'}], 'r1')
        got = pi_daemon.load_wifi_profiles()
        self.assertEqual([p['ssid'] for p in got['profiles']], ['Good'])

    def test_boot_reconcile_skipped_when_nothing_saved(self):
        with mock.patch.object(pi_daemon, '_reconcile_wifi') as rec:
            pi_daemon._reconcile_wifi_from_cache()
        rec.assert_not_called()

    def test_boot_reconcile_uses_cached_list(self):
        pi_daemon.save_wifi_profiles([{'ssid': 'Cafe', 'password': 'pw'}], 'r1')
        with mock.patch.object(pi_daemon, '_reconcile_wifi') as rec:
            pi_daemon._reconcile_wifi_from_cache()
        rec.assert_called_once()
        self.assertEqual(rec.call_args.args[0][0]['ssid'], 'Cafe')

    def test_reconcile_passes_credentials_on_stdin_not_argv(self):
        """A PSK in argv is readable by any local process for the life of the
        call via /proc/<pid>/cmdline."""
        completed = mock.Mock(returncode=0)
        with mock.patch('os.path.exists', return_value=True), \
                mock.patch('subprocess.run', return_value=completed) as run:
            pi_daemon._reconcile_wifi([{'ssid': 'Cafe', 'password': 'sekrit'}])

        argv = run.call_args.args[0]
        self.assertEqual(argv[-1], 'sync')
        self.assertNotIn('sekrit', ' '.join(argv))
        self.assertIn(b'sekrit', run.call_args.kwargs['input'])


class WifiWatchdogTests(unittest.TestCase):
    """NetworkManager treats an associated-but-internet-less AP as success and
    will sit on it forever; this watchdog is the only thing that moves off it."""

    def _state(self):
        return {'hb_fail_since': 0.0}

    def test_success_clears_the_failure_clock(self):
        state = {'hb_fail_since': 1.0}
        pi_daemon._wifi_watchdog(state, ok=True)
        self.assertEqual(state['hb_fail_since'], 0.0)

    def test_no_cycle_when_not_associated(self):
        state = self._state()
        with mock.patch.object(pi_daemon, '_get_wifi_info',
                               return_value={'current': '', 'networks': []}), \
                mock.patch.object(pi_daemon, '_wifi_cycle') as cycle:
            pi_daemon._wifi_watchdog(state, ok=False)
            pi_daemon._wifi_watchdog(state, ok=False)
        cycle.assert_not_called()
        self.assertEqual(state['hb_fail_since'], 0.0)

    def test_no_cycle_before_the_threshold(self):
        state = self._state()
        with mock.patch.object(pi_daemon, '_get_wifi_info',
                               return_value={'current': 'Cafe', 'networks': []}), \
                mock.patch.object(pi_daemon, '_wifi_cycle') as cycle:
            pi_daemon._wifi_watchdog(state, ok=False)   # starts the clock
            pi_daemon._wifi_watchdog(state, ok=False)   # still well inside 5 min
        cycle.assert_not_called()
        self.assertNotEqual(state['hb_fail_since'], 0.0)

    def test_cycles_after_sustained_failure_while_associated(self):
        state = self._state()
        started = []
        with mock.patch.object(pi_daemon, '_get_wifi_info',
                               return_value={'current': 'Cafe', 'networks': []}), \
                mock.patch.object(pi_daemon.threading, 'Thread') as thread:
            thread.side_effect = lambda **kw: started.append(kw) or mock.Mock()
            pi_daemon._wifi_watchdog(state, ok=False)
            state['hb_fail_since'] = time.time() - (pi_daemon._WIFI_WATCHDOG_S + 1)
            pi_daemon._wifi_watchdog(state, ok=False)

        self.assertEqual(len(started), 1)
        self.assertIs(started[0]['target'], pi_daemon._wifi_cycle)
        # Clock resets so it doesn't re-fire on the very next heartbeat.
        self.assertEqual(state['hb_fail_since'], 0.0)


class BootOrderTests(unittest.TestCase):
    """The transmitter must key up before anything touches the network. These
    calls used to sit on the boot path and cost ~115s of dead air on a Pi that
    booted without wifi."""

    def test_main_does_not_call_the_api_before_starting_threads(self):
        import inspect
        src = inspect.getsource(pi_daemon.main)
        body = src.split('threading.Thread')[0]
        for forbidden in ('sync_library(', 'send_heartbeat('):
            self.assertNotIn(
                forbidden, body,
                f'{forbidden} is back on the boot path — it blocks the carrier '
                'coming up when the network is down')

    def test_scheduler_seeds_timers_so_first_tick_syncs_immediately(self):
        import inspect
        src = inspect.getsource(pi_daemon._scheduler_loop)
        # Both must start at 0 so the work main() no longer does happens at once.
        self.assertIn("'last_hb': 0.0", src)
        self.assertIn("'last_sync': 0.0", src)


class CommandHandlingTests(unittest.TestCase):
    """Per-device commands. Every path must ack: from the admin page an
    unacknowledged command is indistinguishable from an offline Pi."""

    def setUp(self):
        self.cfg = {'server_url': 'https://fmplaylist.com', 'api_key': 'tok'}
        self.acks = []
        pi_daemon._fm_suppressed = False

    def tearDown(self):
        pi_daemon._fm_suppressed = False

    def _capture_acks(self):
        def fake_ack(cfg, cmd_id, ok, result):
            self.acks.append({'id': cmd_id, 'ok': ok, 'result': result})
        return mock.patch.object(pi_daemon, '_ack_command', side_effect=fake_ack)

    def test_unknown_command_is_reported_not_dropped(self):
        with self._capture_acks():
            pi_daemon._run_command(self.cfg, {'id': 7, 'command': 'self_destruct'})

        self.assertEqual(len(self.acks), 1)
        self.assertFalse(self.acks[0]['ok'])
        self.assertIn('unknown command', self.acks[0]['result'])

    def test_reboot_acks_before_acting(self):
        order = []
        with mock.patch.object(pi_daemon, '_ack_command',
                               side_effect=lambda *a: order.append('ack')), \
                mock.patch.object(pi_daemon, '_detached_run',
                                  side_effect=lambda *a: order.append('act')):
            pi_daemon._run_command(self.cfg, {'id': 1, 'command': 'reboot'})

        # Reversed, the ack could never be delivered — the box is already down.
        self.assertEqual(order, ['ack', 'act'])

    def test_restart_daemon_uses_detached_run(self):
        with self._capture_acks(), \
                mock.patch.object(pi_daemon, '_detached_run') as det:
            pi_daemon._run_command(self.cfg, {'id': 2, 'command': 'restart_daemon'})

        det.assert_called_once()
        self.assertEqual(det.call_args.args[0], ['systemctl', 'restart', 'fmplaylist'])

    def test_fm_stop_takes_transmitter_off_air_without_stopping_daemon(self):
        with self._capture_acks(), mock.patch.object(pi_daemon, '_stop_fm') as stop:
            pi_daemon._run_command(self.cfg, {'id': 3, 'command': 'fm_stop'})

        stop.assert_called_once()
        self.assertTrue(pi_daemon._fm_suppressed)
        # Going off air is exactly when remote control matters most.
        self.assertFalse(pi_daemon._stop_event.is_set())
        self.assertTrue(self.acks[0]['ok'])

    def test_suppressed_transmitter_does_not_restart_itself(self):
        pi_daemon._fm_suppressed = True
        # The audio thread calls this constantly; without the gate the carrier
        # would come straight back up on the next chunk.
        self.assertFalse(pi_daemon._ensure_fm_running({'freq': 96.9}))

    def test_fm_start_clears_suppression(self):
        pi_daemon._fm_suppressed = True
        with self._capture_acks(), \
                mock.patch.object(pi_daemon, '_ensure_fm_running', return_value=True) as ens:
            pi_daemon._run_command(self.cfg, {'id': 4, 'command': 'fm_start'})

        self.assertFalse(pi_daemon._fm_suppressed)
        ens.assert_called_once()

    def test_failing_command_acks_failure(self):
        with self._capture_acks(), \
                mock.patch.object(pi_daemon, '_run_setup_script',
                                  side_effect=RuntimeError('installer exited 1')):
            pi_daemon._run_command(self.cfg, {'id': 5, 'command': 'rollback'})

        self.assertFalse(self.acks[0]['ok'])
        self.assertIn('installer exited 1', self.acks[0]['result'])

    def test_fetch_logs_returns_journal_output_truncated(self):
        completed = mock.Mock(stdout='x' * 40000, stderr='')
        with mock.patch('subprocess.run', return_value=completed):
            out = pi_daemon._cmd_fetch_logs(self.cfg, '100')

        # Server caps the ack at 20k; an oversized body would be rejected
        # outright and the admin would get nothing at all.
        self.assertLessEqual(len(out), 19000)

    def test_fetch_logs_caps_requested_line_count(self):
        completed = mock.Mock(stdout='ok', stderr='')
        with mock.patch('subprocess.run', return_value=completed) as run:
            pi_daemon._cmd_fetch_logs(self.cfg, '999999')

        self.assertEqual(run.call_args.args[0][-2], '2000')

    def test_update_already_in_progress_acks_failure_not_success(self):
        # Acking success here would tell the admin an update happened when it
        # was silently skipped.
        pi_daemon._update_in_progress = True
        try:
            with self._capture_acks():
                pi_daemon._run_command(self.cfg, {'id': 6, 'command': 'update'})
        finally:
            pi_daemon._update_in_progress = False

        self.assertFalse(self.acks[0]['ok'])
        self.assertIn('already in progress', self.acks[0]['result'])

    def test_handle_commands_ignores_malformed_entries(self):
        with mock.patch.object(pi_daemon.threading, 'Thread') as thread:
            pi_daemon._handle_commands(self.cfg, [
                {'id': 1, 'command': 'fetch_logs'},
                {'command': 'no_id'},
                'not-a-dict',
            ])

        self.assertEqual(thread.call_count, 1)


class StatusFileTests(unittest.TestCase):
    """setup.sh's health check reads this file to decide whether an update is
    good. What it contains directly determines whether a Pi gets rolled back."""

    def setUp(self):
        self.tmpdir = tempfile.TemporaryDirectory()
        self._orig = pi_daemon.STATUS_PATH
        pi_daemon.STATUS_PATH = os.path.join(self.tmpdir.name, 'status.json')
        pi_daemon._fm_suppressed = False

    def tearDown(self):
        pi_daemon.STATUS_PATH = self._orig
        pi_daemon._fm_suppressed = False
        self.tmpdir.cleanup()

    def _write(self):
        pi_daemon._write_status_file({'freq': 96.9}, {'last_hb': time.time()})
        with open(pi_daemon.STATUS_PATH) as f:
            return json.load(f)

    def test_status_reports_transmitter_state(self):
        status = self._write()
        self.assertIn('fm_running', status)
        self.assertIn('updated_at', status)

    def test_status_distinguishes_suppressed_from_broken(self):
        pi_daemon._fm_suppressed = True
        status = self._write()

        # Without this flag, setup.sh cannot tell "admin took it off air" from
        # "the update broke the transmitter", and rolls back a good install.
        self.assertFalse(status['fm_running'])
        self.assertTrue(status['fm_suppressed'])


class LastUpdateReportingTests(unittest.TestCase):
    def setUp(self):
        self.tmpdir = tempfile.TemporaryDirectory()
        self._orig = pi_daemon.LAST_UPDATE_PATH
        pi_daemon.LAST_UPDATE_PATH = os.path.join(self.tmpdir.name, 'last-update.json')

    def tearDown(self):
        pi_daemon.LAST_UPDATE_PATH = self._orig
        self.tmpdir.cleanup()

    def test_missing_file_is_not_an_error(self):
        self.assertEqual(pi_daemon._read_last_update(), {})

    def test_rollback_result_is_readable(self):
        with open(pi_daemon.LAST_UPDATE_PATH, 'w') as f:
            json.dump({'result': 'rolled_back', 'message': 'health check failed'}, f)

        # A rollback the admin never saw happen is otherwise invisible.
        self.assertEqual(pi_daemon._read_last_update()['result'], 'rolled_back')

    def test_corrupt_file_is_not_an_error(self):
        with open(pi_daemon.LAST_UPDATE_PATH, 'w') as f:
            f.write('{truncated')

        self.assertEqual(pi_daemon._read_last_update(), {})


if __name__ == '__main__':
    unittest.main()
