$logFile = Join-Path $PWD "next-dev.log"
$job = Start-Job -Name "nextjs" -ScriptBlock {
    param($dir, $log)
    Set-Location $dir
    npx next dev -p 3100 *> $log
} -ArgumentList $PWD, $logFile
$job.Id | Out-File (Join-Path $PWD "next.pid") -Encoding ascii
