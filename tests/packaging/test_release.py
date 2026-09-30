import importlib.util
from pathlib import Path
import tempfile
import unittest
import zipfile

spec = importlib.util.spec_from_file_location('release', Path(__file__).resolve().parents[2] / 'tools/release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)

class ReleaseTests(unittest.TestCase):
    def test_private_and_generated_files_never_enter_source_archive(self):
        cases = ['infra/compose/.env', 'apps/server/.env.production', 'infra/backups/backup.sql',
                 'apps/server/vendor/a/LICENSE', 'apps/client/lib/unit.ppu', 'apps/server/auth.json',
                 'apps/server/storage/framework/sessions/session', 'apps/server/bootstrap/cache/services.php',
                 'apps/server/database/database.sqlite', 'apps/server/database/database.sqlite-wal']
        for path in cases:
            with self.subTest(path=path):
                self.assertFalse(release.allowed(path))
        self.assertTrue(release.allowed('infra/compose/.env.production.example'))
        self.assertTrue(release.allowed('apps/server/storage/framework/views/.gitignore'))

    def test_archive_checksums_and_roundtrip(self):
        old = release.OUT
        with tempfile.TemporaryDirectory() as tmp:
            release.OUT = Path(tmp)
            try:
                release.archive('test', {'README.txt': b'hello'})
                with zipfile.ZipFile(Path(tmp) / 'test.zip') as z:
                    self.assertEqual(z.read('test/README.txt'), b'hello')
                    self.assertIn(release.hashlib.sha256(b'hello').hexdigest(), z.read('test/SHA256SUMS.txt').decode())
            finally:
                release.OUT = old

    def test_tag_must_match_version(self):
        with self.assertRaises(SystemExit):
            release.check('v999.0.0')

if __name__ == '__main__':
    unittest.main()
