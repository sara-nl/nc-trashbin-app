## SURF Trashbin app

#### Enable restore (and permanent delete) capabilities for the project owner of files/folders deleted by project users.
___
The sharing scheme is:<br>
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`f_account -> project-owner -> project-users`\
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(the functional account shares a folder with a designated user, the project owner, who shares the folder with project users)

When a file/folder is deleted by a project user:
* the project owner gets a copy of the deleted node in it's trashbin as well, so the owner can restore it

When a file/folder is restored by a project user:
* the trashbin of the project owner will be cleaned up as well

When a file/folder is restored by the project owner:
* the trashbin of the user who deleted the file will be cleaned up as well

After restoring the trashbin/filecache of the f_account is cleaned up as well (something that does not happen automatically)

___
*&nbsp;Note that it does not matter whether project owners or users have quota or not, the effect is the same.

## Tests

The end-to-end test starts the official Nextcloud Docker image, installs the app from this
checkout and builds the sharing scheme above: a functional account shares a project folder with
the project owner, who reshares it with a project user and a project user without quota. It then
deletes, restores and permanently deletes files and folders through WebDAV, as the Files app
does, and checks the trashbin of every party. Any error in the Nextcloud log fails the run.

```bash
tests/e2e/run-docker.sh 35          # image tag: 31, 34, 35, latest, ...
FORCE_ENABLE=1 tests/e2e/run-docker.sh latest   # a version newer than max-version
```

It needs Docker, curl and python3. `tests/e2e/e2e.sh` can also test an existing server over ssh;
see the comment at the top of the script.

`composer psalm` runs static analysis on `lib/`. `\OC\Files\View` and the files_trashbin events
are not part of `nextcloud/ocp`, so `tests/stubs/server.php` describes the classes and methods
this app uses; add a method there when the code starts using it.

GitHub Actions runs `composer lint`, `composer cs:check` and the end-to-end test on Nextcloud 31,
34 and 35 for every pull request, and monthly against the newest Nextcloud release. Psalm runs
against the `nextcloud/ocp` of the oldest and the newest Nextcloud that `info.xml` allows.
