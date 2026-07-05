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
                mock.patch('subprocess.run', return_value=completed) as run, \
                mock.patch.object(pi_daemon, '_stop_fm'), \
                mock.patch.object(pi_daemon.os, '_exit', side_effect=SystemExit):
            with self.assertRaises(SystemExit):
                pi_daemon._apply_daemon_update({
                    'server_url': 'https://fmplaylist.com',
                    'api_key': 'token123',
                })

        request = urlopen.call_args.args[0]
        self.assertEqual(request.full_url, 'https://fmplaylist.com/pi/setup.sh')
        opened.assert_called()
        chmod.assert_called()
        run.assert_called_once()
        self.assertEqual(run.call_args.args[0], ['bash', mock.ANY, 'token123'])


if __name__ == '__main__':
    unittest.main()
