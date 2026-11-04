#!/usr/bin/env bash
# End-to-end test against a running Nextcloud with this app enabled.
#
# usage: tests/e2e/e2e.sh <base_url> docker <container>
#        E2E_PW=<password> tests/e2e/e2e.sh <base_url> ssh <user@host> <nextcloud_path>
#
# Builds the Research Drive sharing scheme: the functional account f_tbtest shares the folder
# tbtest-project with the project owner tbtestowner, who reshares it with the project users
# tbtestuser and tbtestzero (zero quota). Then deletes, restores and permanently deletes files
# and folders through WebDAV, as the Files app does, and checks every party's trashbin.
# The test users are deleted and created again at the start, so every run starts clean.
# In ssh mode E2E_PW is required, because the test users would otherwise get a password that
# is public in this repository. Set E2E_CLEANUP=1 to delete the test users afterwards.
# Needs curl and python3.
set -uo pipefail
BASE=$1; MODE=$2; shift 2
if [ "$MODE" = docker ]; then CONTAINER=$1; else HOST=$1; NCPATH=$2; fi

if [ "$MODE" = ssh ] && [ -z "${E2E_PW:-}" ]; then
	echo "Set E2E_PW to a password of your own when testing a server over ssh." >&2
	exit 2
fi
PW=${E2E_PW:-'Tb-e2e-Pw-2026-Qm7!'}
F=f_tbtest; O=tbtestowner; U=tbtestuser; Z=tbtestzero
P=tbtest-project
pass=0; fail=0

occ() {
	if [ "$MODE" = docker ]; then
		docker exec -e OC_PASS="$PW" -u www-data "$CONTAINER" php occ "$@"
	else
		ssh -o BatchMode=yes "$HOST" "sudo -n -u www-data env OC_PASS=$(printf '%q' "$PW") php $NCPATH/occ $(printf '%q ' "$@")"
	fi
}
check() { if [ "$2" = "$3" ]; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1 (expected '$3', got '$2')"; fail=$((fail+1)); fi; }

# WebDAV request as <user> on <path> below remote.php/dav, prints the http status
dav() { # <user> <method> <path> [curl args]
	local user=$1 method=$2 path=$3; shift 3
	curl -s -o /dev/null -w '%{http_code}' -u "$user:$PW" -X "$method" "$@" "$BASE/remote.php/dav/$path"
}
put() { dav "$1" PUT "files/$1/$2" --data-binary "$3"; }   # <user> <path> <content>
mkcol() { dav "$1" MKCOL "files/$1/$2"; }                   # <user> <path>
delete() { dav "$1" DELETE "files/$1/$2"; }                 # <user> <path>
content() { curl -s -u "$1:$PW" "$BASE/remote.php/dav/files/$1/$2"; }
# Shares <path> of <owner> with <recipient> with all permissions, prints the OCS status code
share() { # <owner> <path> <recipient>
	curl -s -u "$1:$PW" -H 'OCS-APIRequest: true' -H 'Accept: application/json' -X POST \
		-d "path=$2" -d shareType=0 -d "shareWith=$3" -d permissions=31 \
		"$BASE/ocs/v2.php/apps/files_sharing/api/v1/shares" |
		python3 -c 'import json,sys; print(json.load(sys.stdin)["ocs"]["meta"]["statuscode"])'
}

# Trashbin entries of <user>, one "<entry>\t<original name>" line each. The entry is the
# name in the trashbin ({name}.d{timestamp}), used to restore or delete it.
trash() {
	curl -s -u "$1:$PW" -X PROPFIND -H 'Depth: 1' --data-binary \
		'<d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:prop><nc:trashbin-filename/></d:prop></d:propfind>' \
		"$BASE/remote.php/dav/trashbin/$1/trash" |
		python3 -c 'import sys, urllib.parse, xml.etree.ElementTree as ET
ns = {"d": "DAV:", "nc": "http://nextcloud.org/ns"}
for r in ET.parse(sys.stdin).getroot().findall("d:response", ns):
    name = r.find(".//nc:trashbin-filename", ns)
    if name is not None and name.text:
        entry = urllib.parse.unquote(r.find("d:href", ns).text.rstrip("/").rsplit("/", 1)[1])
        print(entry + "\t" + name.text)'
}
entry_of() { trash "$1" | awk -F '\t' -v n="$2" '$2 == n { print $1; exit }'; }   # <user> <name>
in_trash() { [ -n "$(entry_of "$1" "$2")" ] && echo yes || echo no; }            # <user> <name>
# Status of restoring / permanently deleting the trashbin entry of <name>, "none" without entry
restore() {
	local e; e=$(entry_of "$1" "$2"); [ -n "$e" ] || { echo none; return; }
	dav "$1" MOVE "trashbin/$1/trash/$e" -H "Destination: $BASE/remote.php/dav/trashbin/$1/restore/$e"
}
purge() {
	local e; e=$(entry_of "$1" "$2"); [ -n "$e" ] || { echo none; return; }
	dav "$1" DELETE "trashbin/$1/trash/$e"
}
# Content of <path> inside the trashbin entry of <name>
trash_content() { # <user> <name> <path>
	local e; e=$(entry_of "$1" "$2")
	curl -s -u "$1:$PW" "$BASE/remote.php/dav/trashbin/$1/trash/$e${3:+/$3}"
}
# yes when <name> is in the trashbin of each given user, e.g. "yes yes no"
trashes() { local n=$1 u r=(); shift; for u in "$@"; do r+=("$(in_trash "$u" "$n")"); done; echo "${r[*]}"; }

# --- setup ---------------------------------------------------------------
for u in $F $O $U $Z; do
	occ user:delete $u >/dev/null 2>&1
	occ user:add --password-from-env --display-name="$u" $u >/dev/null || { echo "Could not create user $u" >&2; exit 1; }
done
occ user:setting $Z files quota '0 B' >/dev/null

version=$(occ status --output=json | python3 -c 'import json,sys; print(json.load(sys.stdin)["versionstring"])')
echo "== Nextcloud $version"

check "the functional account creates the project folder"  "$(mkcol $F $P)" 201
mkcol $F "$P/docs" >/dev/null; mkcol $F "$P/docs/sub" >/dev/null
for f in a b d e; do put $F "$P/$f.txt" "content of $f" >/dev/null; done
put $F "$P/docs/sub/c.txt" "content of c" >/dev/null
check "the functional account shares it with the owner"   "$(share $F "/$P" $O)" 200
check "the owner reshares it with a project user"         "$(share $O "/$P" $U)" 200
check "the owner reshares it with a zero quota user"      "$(share $O "/$P" $Z)" 200
check "the project user sees the project folder"          "$(content $U "$P/a.txt")" "content of a"

# Each trashes line lists: functional account, owner, project user

# --- a project user deletes a file, the owner restores it ---------------
check "a project user deletes a file"                     "$(delete $U "$P/a.txt")" 204
check "the file is in every trashbin"                     "$(trashes a.txt $F $O $U)" "yes yes yes"
check "the owner's copy has the content of the file"      "$(trash_content $O a.txt '')" "content of a"
check "the owner restores the file"                       "$(restore $O a.txt)" 201
check "the file is back in the project folder"            "$(content $F "$P/a.txt")" "content of a"
check "the file is gone from every trashbin"              "$(trashes a.txt $F $O $U)" "no no no"

# --- a project user deletes a file and restores it -----------------------
delete $U "$P/b.txt" >/dev/null
check "the owner gets a copy of the deleted file"         "$(trashes b.txt $F $O $U)" "yes yes yes"
check "the project user restores the file"                "$(restore $U b.txt)" 201
check "the restored file is back in the project folder"   "$(content $O "$P/b.txt")" "content of b"
check "the file is gone from every trashbin after restore" "$(trashes b.txt $F $O $U)" "no no no"

# --- a folder, and a permanent delete by one party ------------------------
delete $U "$P/docs" >/dev/null
check "a deleted folder is in every trashbin"             "$(trashes docs $F $O $U)" "yes yes yes"
check "the owner's copy of the folder is complete"        "$(trash_content $O docs sub/c.txt)" "content of c"
check "the owner deletes the folder permanently"          "$(purge $O docs)" 204
check "the other copies are kept"                         "$(trashes docs $F $O $U)" "yes no yes"
check "the project user's copy is still complete"         "$(trash_content $U docs sub/c.txt)" "content of c"
check "the project user restores the folder"              "$(restore $U docs)" 201
check "the restored folder is complete"                   "$(content $F "$P/docs/sub/c.txt")" "content of c"
check "the folder is gone from every trashbin"            "$(trashes docs $F $O $U)" "no no no"

# --- a project user without quota -----------------------------------------
delete $Z "$P/d.txt" >/dev/null
check "a user without quota still gets a trashbin copy"   "$(trashes d.txt $F $O $Z)" "yes yes yes"
check "the copy of the user without quota has the content" "$(trash_content $Z d.txt '')" "content of d"
check "the owner restores the file of that user"          "$(restore $O d.txt)" 201
check "that file is gone from every trashbin"             "$(trashes d.txt $F $O $Z)" "no no no"

# --- the owner deletes a file ---------------------------------------------
delete $O "$P/e.txt" >/dev/null
check "a file the owner deletes is in their trashbin"     "$(trashes e.txt $F $O)" "yes yes"
check "the owner's delete gives the project user no copy" "$(in_trash $U e.txt)" no
check "the owner restores their own delete"               "$(restore $O e.txt)" 201
check "the owner's file is gone from every trashbin"      "$(trashes e.txt $F $O)" "no no"

# --- outside a functional account folder ----------------------------------
put $U own.txt "own" >/dev/null
delete $U own.txt >/dev/null
check "a user's own file only goes to their own trashbin" "$(trashes own.txt $F $O $U)" "no no yes"
check "a user's own file can be restored"                 "$(restore $U own.txt)" 201
# A regular share also leaves a trashbin row for the sharer and for the deleter
mkcol $O tbtest-shared >/dev/null
put $O tbtest-shared/g.txt "content of g" >/dev/null
share $O /tbtest-shared $U >/dev/null
delete $U tbtest-shared/g.txt >/dev/null
check "a file deleted from a regular share is in both trashbins" "$(trashes g.txt $O $U)" "yes yes"
check "the user restores the file of the regular share"   "$(restore $U g.txt)" 201
check "the file of the regular share is back"             "$(content $O tbtest-shared/g.txt)" "content of g"

if [ "${E2E_CLEANUP:-0}" = 1 ]; then
	for u in $F $O $U $Z; do occ user:delete $u >/dev/null; done
fi

echo "== $pass passed, $fail failed"
[ "$fail" = 0 ]
