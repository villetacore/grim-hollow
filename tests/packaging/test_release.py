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

    def test_laravel_cache_placeholder_is_packaged_without_cache_data(self):
        placeholder = 'apps/server/storage/framework/cache/data/.gitignore'
        self.assertTrue(release.allowed(placeholder))
        old_root = release.ROOT
        with tempfile.TemporaryDirectory() as tmp:
            release.ROOT = Path(tmp)
            try:
                files = {
                    placeholder: b'*\n!.gitignore\n',
                    'apps/server/storage/framework/cache/data/cached-value': b'private',
                    'infra/data/.gitignore': b'*',
                    'apps/server/vendor/example/.gitignore': b'*',
                }
                for name, data in files.items():
                    path = release.ROOT / name
                    path.parent.mkdir(parents=True, exist_ok=True)
                    path.write_bytes(data)
                    if name != placeholder:
                        self.assertFalse(release.allowed(name))
                self.assertEqual(
                    [p.relative_to(release.ROOT).as_posix() for p in release.source_files()],
                    [placeholder],
                )
            finally:
                release.ROOT = old_root

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
