$entry = "`n# Plesk local domains`n127.0.0.1 laravel.plesk"
Add-Content -Path "C:\Windows\System32\drivers\etc\hosts" -Value $entry
Write-Host "Done"
