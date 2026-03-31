@echo off
setlocal enabledelayedexpansion

:: Get the current workspace path (passed by VS Code as the last argument usually, or contained in the arguments)
:: However, VS Code calls php -l <filename>
:: We need to replace the local path with the container path.

set "ARGS=%*"
:: Replace backslashes with forward slashes for the container
set "ARGS=%ARGS:\=/%"
:: Replace the project root with the container root
:: Note: We use the absolute path of the user here.
set "ARGS=%ARGS:c:/Users/mihai/Documents/GitHub/sym-pgp-ony=/var/www/app%"
:: Also handle lowercase drive letter just in case
set "ARGS=%ARGS:C:/Users/mihai/Documents/GitHub/sym-pgp-ony=/var/www/app%"

docker exec -i php php %ARGS%
