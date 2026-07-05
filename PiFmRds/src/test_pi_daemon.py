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


if __name__ == '__main__':
    unittest.main()
