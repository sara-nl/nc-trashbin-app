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
