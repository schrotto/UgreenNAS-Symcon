"""Local test server for the authenticated UGOS WebSocket upgrade."""
from base64 import b64encode
from contextlib import contextmanager, suppress
from hashlib import sha1
from http.client import HTTPConnection
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import pathlib
import re
from threading import Thread
from urllib.parse import parse_qs, urlsplit


@contextmanager
def websocket_fixture(backend_port, directory):
    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *_args):
            pass

        def do_GET(self):
            url = urlsplit(self.path)
            if url.path == "/ugreen/v1/desktop/ws":
                self.upgrade(parse_qs(url.query))
            else:
                self.proxy()

        def do_POST(self):
            self.proxy()

        def proxy(self):
            body = self.rfile.read(int(self.headers.get("Content-Length", "0")))
            with suppress(BrokenPipeError, ConnectionResetError):
                conn = HTTPConnection("127.0.0.1", backend_port, timeout=10)
                try:
                    conn.request(self.command, self.path, body or None, dict(self.headers.items()))
                    reply = conn.getresponse()
                    data = reply.read()
                    self.send_response(reply.status)
                    for name, value in reply.getheaders():
                        if name.lower() in {"content-type", "set-cookie", "x-rsa-token"}:
                            self.send_header(name, value)
                    self.send_header("Content-Length", str(len(data)))
                    self.end_headers()
                    self.wfile.write(data)
                finally:
                    conn.close()

        def upgrade(self, query):
            token = query.get("token", [""])[0]
            client = query.get("client_id", [""])[0]
            key = self.headers.get("Sec-WebSocket-Key", "")
            if not re.fullmatch(r"ws(?:live|bad)-\d+", token) or not key:
                self.send_error(404)
                return
            if (not re.fullmatch(r"[0-9a-f-]{36}-WEB", client) or query.get("lang") != ["de-DE"]
                or self.headers.get("Sec-WebSocket-Version") != "13"
                or self.headers.get("Upgrade") != "websocket" or self.headers.get("Connection") != "Upgrade"
                or self.headers.get("Origin") != "http://" + self.headers.get("Host", "")
                or "token_uid=1000" not in self.headers.get("Cookie", "")):
                self.send_error(400)
                return
            self.connection.settimeout(12)
            guid = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"
            accept = b64encode(sha1((key + guid).encode()).digest()).decode()
            self.send_response(101)
            self.send_header("Upgrade", "websocket")
            self.send_header("Connection", "upgrade")
            self.send_header("Sec-WebSocket-Accept", accept if token.startswith("wslive-") else "invalid")
            self.end_headers()
            self.close_connection = True
            with suppress(ConnectionResetError, TimeoutError):
                header = self.rfile.read(2)
                if token.startswith("wsbad-"):
                    if header:
                        (pathlib.Path(directory) / "invalid-handshake-frame").touch()
                    return
                if len(header) != 2 or header[0] != 0x81 or not 0x80 < header[1] < 0xFE:
                    return
                mask = self.rfile.read(4)
                payload = self.rfile.read(header[1] & 0x7F)
                message = json.loads(bytes(byte ^ mask[index % 4] for index, byte in enumerate(payload)))
                if message.get("op") != "subscribe" or message.get("topics") != ["cpu_usage", "cpu_temp"]:
                    return
                marker = pathlib.Path(directory) / token
                marker.touch()
                try:
                    self.rfile.read(1)
                finally:
                    marker.unlink()

    with ThreadingHTTPServer(("127.0.0.1", 0), Handler) as server:
        thread = Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            yield f"http://127.0.0.1:{server.server_port}"
        finally:
            server.shutdown()
            thread.join(timeout=5)
