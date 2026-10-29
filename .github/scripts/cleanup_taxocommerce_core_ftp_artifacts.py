#!/usr/bin/env python3
"""Remove only legacy Core deployment artifacts that Plugin Check flags.

This is deliberately restricted to known developer test scripts and the old
FTP-Deploy-Action state file. Runtime plugin files and unknown remote files
are never removed.
"""

import ftplib
import os

PLUGIN_DIR = "./wp-content/plugins/taxocommerce-core/"
LEGACY_TESTS = (
    "anomalies-smoke.php",
    "architecture-report-smoke.php",
    "auto-test-navigation-smoke.php",
    "auto-test-smoke.php",
    "content-inventory-smoke.php",
    "country-scope-smoke.php",
    "editorial-guide-smoke.php",
    "extensions-dashboard-smoke.php",
    "family-menu-smoke.php",
    "hierarchy-country-schema-smoke.php",
    "menu-editor-smoke.php",
    "modular-architecture-smoke.php",
    "user-guide-smoke.php",
)


def delete_if_present(ftp, path):
    try:
        ftp.delete(path)
    except ftplib.error_perm as error:
        if not str(error).startswith("550"):
            raise
    else:
        print("Removed legacy Core artifact:", path)


def main():
    ftp = ftplib.FTP_TLS(timeout=45)
    ftp.connect(os.environ["TC_CORE_FTP_HOST"], 21)
    ftp.login(os.environ["TC_CORE_FTP_USER"], os.environ["TC_CORE_FTP_PASS"])
    ftp.prot_p()
    try:
        ftp.cwd(PLUGIN_DIR)
        delete_if_present(ftp, ".ftp-deploy-sync-state.json")
        try:
            ftp.cwd("tests")
        except ftplib.error_perm as error:
            if not str(error).startswith("550"):
                raise
            print("Core tests directory already absent")
            return
        ftp.cwd("..")
        for filename in LEGACY_TESTS:
            delete_if_present(ftp, "tests/" + filename)
        try:
            ftp.rmd("tests")
        except ftplib.error_perm as error:
            if not str(error).startswith("550"):
                raise
            # Both staging workflows may clean at once. A missing directory
            # is success; an existing nonempty directory is a real failure.
            try:
                ftp.cwd("tests")
            except ftplib.error_perm as missing:
                if not str(missing).startswith("550"):
                    raise
                print("Core tests directory already removed by another deploy")
                return
            ftp.cwd("..")
            raise RuntimeError(
                "Core tests directory still contains unknown files. "
                "They were preserved for review."
            ) from error
        print("Removed obsolete Core tests directory")
    finally:
        ftp.quit()


if __name__ == "__main__":
    main()
