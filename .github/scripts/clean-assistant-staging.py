#!/usr/bin/env python3
"""Remove ONLY legacy CI fixtures and the FTP state from the STAGING Assistant plugin.

FTP-Deploy-Action uploads from its source folder and keeps a hidden state file in
the remote plugin folder. Neither the state file nor local `tests/` belongs in
a WordPress installation. Tests remain in GitHub and run before deployment.
No files outside the Assistant plugin root may be modified.
"""
import ftplib
import os
import posixpath
import ssl
import sys

REMOTE_PATH = "wp-content/plugins/taxocommerce-dependiente"
STATE_FILE = ".ftp-deploy-sync-state.json"


def remove_directory(ftp):
    """Recursive deletion, called ONLY after CWD into the exact tests directory."""
    entries = ftp.nlst()
    for entry in entries:
        name = posixpath.basename(entry.rstrip("/"))
        if not name or name in {".", ".."} or "/" in name or "\\" in name:
            raise RuntimeError("Unsafe FTP directory entry: {!r}".format(entry))
        try:
            ftp.cwd(name)
        except ftplib.error_perm as exc:
            if not str(exc).startswith("550"):
                raise
            ftp.delete(name)
        else:
            remove_directory(ftp)
            ftp.cwd("..")
            ftp.rmd(name)


def main():
    host = os.environ["FTP_SERVER"]
    user = os.environ["FTP_USERNAME"]
    password = os.environ["FTP_PASSWORD"]
    ftp = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=45)
    try:
        ftp.connect(host, 21)
        ftp.login(user, password)
        ftp.prot_p()
        ftp.cwd(REMOTE_PATH)
        root = ftp.pwd()

        # Restrict all deletions to the known plugin root and exact obsolete names.
        for filename in (STATE_FILE,):
            try:
                ftp.delete(filename)
                print("Removed obsolete Assistant FTP state")
            except ftplib.error_perm as exc:
                if not str(exc).startswith("550"):
                    raise
                print("No obsolete Assistant FTP state found")

        try:
            ftp.cwd("tests")
        except ftplib.error_perm as exc:
            if not str(exc).startswith("550"):
                raise
            print("No stale tests directory found in Assistant")
        else:
            if not ftp.pwd().rstrip("/").endswith("/taxocommerce-dependiente/tests"):
                raise RuntimeError("Refusing cleanup: unexpected remote FTP directory")
            remove_directory(ftp)
            ftp.cwd(root)
            ftp.rmd("tests")
            print("Removed stale Assistant tests directory; all test sources remain in GitHub")
    finally:
        try:
            ftp.quit()
        except (OSError, ftplib.all_errors):
            ftp.close()


if __name__ == "__main__":
    try:
        main()
    except (OSError, ftplib.all_errors, RuntimeError) as exc:
        print("Assistant staging cleanup failed: {}".format(exc), file=sys.stderr)
        sys.exit(1)
