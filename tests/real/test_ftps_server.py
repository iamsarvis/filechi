#!/usr/bin/env python3
"""
Real FTPS Server and Test Runner (Item 4.5)

Starts a local FTPS server with explicit TLS (pyftpdlib), generates a self-signed
RSA certificate, computes its SPKI SHA256 pin, and runs the full suite of real
driver checks (discovery, wrong pin curl 90, right pin, upload, size, stream, delete).
"""

import sys
import os
import shutil
import tempfile
import threading
import subprocess
import datetime
import base64

from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.primitives import serialization, hashes
from cryptography import x509
from cryptography.x509.oid import NameOID

from pyftpdlib.authorizers import DummyAuthorizer
from pyftpdlib.handlers import TLS_FTPHandler
from pyftpdlib.servers import FTPServer


def generate_self_signed_cert_and_pin(cert_path: str, key_path: str) -> str:
    """Generates an RSA private key and self-signed certificate, returning the SPKI SHA256 pin."""
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)

    # Save private key
    with open(key_path, "wb") as f:
        f.write(
            key.private_bytes(
                encoding=serialization.Encoding.PEM,
                format=serialization.PrivateFormat.TraditionalOpenSSL,
                encryption_algorithm=serialization.NoEncryption(),
            )
        )

    # Subject and Issuer
    subject = issuer = x509.Name([
        x509.NameAttribute(NameOID.COMMON_NAME, "127.0.0.1"),
        x509.NameAttribute(NameOID.ORGANIZATION_NAME, "FileChi Test FTPS"),
    ])

    cert = (
        x509.CertificateBuilder()
        .subject_name(subject)
        .issuer_name(issuer)
        .public_key(key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=1))
        .not_valid_after(datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(days=365))
        .add_extension(
            x509.SubjectAlternativeName([x509.IPAddress(ip) for ip in [__import__("ipaddress").ip_address("127.0.0.1")]]),
            critical=False,
        )
        .sign(key, hashes.SHA256())
    )

    with open(cert_path, "wb") as f:
        f.write(cert.public_bytes(serialization.Encoding.PEM))

    # Calculate SPKI SHA-256 pin
    spki_der = key.public_key().public_bytes(
        encoding=serialization.Encoding.DER,
        format=serialization.PublicFormat.SubjectPublicKeyInfo,
    )
    digest = hashes.Hash(hashes.SHA256())
    digest.update(spki_der)
    pin = "sha256//" + base64.b64encode(digest.finalize()).decode("ascii")
    return pin


def main():
    temp_dir = tempfile.mkdtemp(prefix="filechi_ftps_")
    ftp_home = os.path.join(temp_dir, "ftphome")
    os.makedirs(ftp_home, exist_ok=True)
    uploads_dir = os.path.join(ftp_home, "uploads")
    os.makedirs(uploads_dir, exist_ok=True)

    cert_path = os.path.join(temp_dir, "server.crt")
    key_path = os.path.join(temp_dir, "server.key")

    try:
        pin = generate_self_signed_cert_and_pin(cert_path, key_path)
        print(f"Generated test certificate. Public key pin: {pin}")

        authorizer = DummyAuthorizer()
        authorizer.add_user("testuser", "testpass", ftp_home, perm="elradfmwMT")

        class FileChiTLSHandler(TLS_FTPHandler):
            certfile = cert_path
            keyfile = key_path
            tls_control_required = True
            tls_data_required = True
            passive_ports = None

        FileChiTLSHandler.authorizer = authorizer

        # Bind to loopback on an ephemeral port
        server = FTPServer(("127.0.0.1", 0), FileChiTLSHandler)
        server.max_cons = 256
        server.max_cons_per_ip = 256
        port = server.socket.getsockname()[1]
        print(f"FTPS server listening on 127.0.0.1:{port} (explicit TLS required)")

        server_thread = threading.Thread(target=server.serve_forever, daemon=True)
        server_thread.start()

        # Locate PHP binary
        php_candidates = [
            r"C:\wamp64\bin\php\php8.3.28\php.exe",
            r"C:\wamp64\bin\php\php8.2.30\php.exe",
            shutil.which("php"),
        ]
        php_bin = next((p for p in php_candidates if p and os.path.exists(p)), "php")

        script_dir = os.path.dirname(os.path.abspath(__file__))
        check_script = os.path.join(script_dir, "check_ftps_real.php")
        if len(sys.argv) > 1 and sys.argv[1].endswith(".php"):
            check_script = os.path.abspath(sys.argv[1])

        cmd = [php_bin, check_script, "127.0.0.1", str(port), "testuser", "testpass", pin, "/uploads"]
        print(f"Executing checks with absolute root /uploads: {' '.join(cmd)}\n")

        res = subprocess.run(cmd)

        server.close_all()
        return res.returncode

    finally:
        shutil.rmtree(temp_dir, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
