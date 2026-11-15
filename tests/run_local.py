"""Isolierter lokaler Testlauf. Benötigt PHP 8.2+ mit curl/openssl/mbstring.

PHP_BINARY, PHP_EXTENSION_DIR und SYMCON_STUBS können gesetzt werden.
Der Testserver lauscht ausschließlich auf 127.0.0.1 und verwendet temporäre Schlüssel.
"""
import os
import pathlib
import socket
import subprocess
import tempfile
import time

root = pathlib.Path(__file__).resolve().parents[1]
php = [os.environ.get('PHP_BINARY', 'php')]
if os.environ.get('PHP_EXTENSION_DIR'):
    php += ['-n']
    for ext in ['curl', 'mbstring']:
        php += ['-d', 'extension=' + os.environ['PHP_EXTENSION_DIR'] + '/' + ext + '.so']
with tempfile.TemporaryDirectory(prefix='ugnas-test-') as directory:
    env = os.environ | {'UGNAS_TEST_TMP': directory}
    key_script = '$k=openssl_pkey_new(["private_key_bits"=>2048]);openssl_pkey_export($k,$p);file_put_contents(getenv("UGNAS_TEST_TMP")."/key.pem",$p);'
    subprocess.run(php + ['-r', key_script], env=env, check=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    env['UGNAS_TEST_URL'] = f'http://127.0.0.1:{port}'
    with open(pathlib.Path(directory) / 'http.log', 'w') as log:
        server = subprocess.Popen(php + ['-S', f'127.0.0.1:{port}', str(root / 'tests/http-router.php')], env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.05)
            for file in root.rglob('*.php'):
                subprocess.run(php + ['-l', str(file)], check=True, stdout=subprocess.DEVNULL)
            subprocess.run(php + [str(root / 'tests/run.php')], env=env, check=True)
            if env.get('SYMCON_STUBS'):
                subprocess.run(php + [str(root / 'tests/symcon.php')], env=env, check=True)
        finally:
            server.terminate()
            server.wait(timeout=5)
