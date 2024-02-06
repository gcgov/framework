Push-Location $PSScriptRoot

if($PSScriptRoot.ToString().IndexOf("vendor") -ne -1) {
    $path = $PSScriptRoot.ToString().substring( 0, $PSScriptRoot.ToString().IndexOf("vendor") ) + 'srv\jwtCertificates'
    Write-Host "Creating certificates and config in $path dir" -ForegroundColor Green
} else {
    Write-Host "create-jwt-keys.ps1 can only be run from `vendor/bin` "
    exit
}

if(!(test-path $path))
{
    New-Item -ItemType Directory -Force -Path $path | out-null
}

if(!(test-path "$path\.gitignore"))
{
    Copy-Item "jwtCertificates/.gitignore" -Destination $path | out-null
}

Remove-Item $path/*.pem | out-null

if(test-path "$path/guids.json")
{
    Remove-Item $path/guids.json | out-null
}

$guids = @()
for ($i = 0; $i -lt 5; $i++)
{
    $guid = New-Guid
    $guids += $guid.ToString()

    openssl genrsa -out $path/private-$guid.pem 2048

    openssl rsa -in $path/private-$guid.pem -outform PEM -pubout -out $path/public-$guid.pem

}

$guids.ForEach({ Write-Host $_ })

$guids | ConvertTo-Json |  Out-File "$path/guids.json"

Pop-Location
