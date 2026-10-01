@echo off
call "D:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\VC\Auxiliary\Build\vcvars64.bat" >nul 2>&1
cd /d D:\git\php\typephp-qt\examples\hello
D:\git\php\tpc_v0.9.4_windows_x64\tpc.exe project.yml -O2
