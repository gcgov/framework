Write-Host $pwd
if ($PSScriptRoot.ToString().IndexOf("vendor") -ne -1)
{
    $path = $PSScriptRoot.ToString().substring(0,$PSScriptRoot.ToString().IndexOf("vendor"))
    Push-Location $path
}
else
{
    Write-Host "setup.ps1 can only be run using command: composer run-script gcgov-setup"
    exit
}

Function FormatRelativeUrl()
{
    param(
        [string]$path,
        [bool]$trailingSlash = $true,
        [bool]$leadingSlash = $true
    )

    $path = $path.Trim('/') # Remove leading/trailing spaces
    if ($trailingSlash -And $path[-1] -ne "/")
    {
        # If the last character is not a slash, add one
        $path += "/"
    }
    if ($leadingSlash -And $path[0] -ne "/")
    {
        # If the first character is not a slash, add one
        $path = "/" + $path
    }
    $path = $path.Replace("\", "/") # Replace backslashes with forward slashes
    $path = $path.Replace("//", "/") # Replace double slashes with single slashes
    return $path
}


$choices = '&Yes', '&No'
$defineMicrosoftIds = $Host.UI.PromptForChoice('Microsoft Services', 'Do you want to define Microsoft Azure App ids during set up?', $choices, 1)

Write-Host "To skip replacing a value, press enter"
# Initialize an empty hashtable to store the user inputs
$inputs = @{}

# Define the prompts in an array
$prompts = @(
    @{ "key"="app_title"; "label"="Enter human readable title of application (ex: Timesheet API)" }
    @{ "key"="app_root_url"; "label"="DEV Root url of app (ex: https://local-app.garrettcountymd.gov/)" }
    @{ "key"="app_base_path"; "label"="DEV Base url path of app (ex: /api/, Or: / if site is at url root)" }
    @{ "key"="app_frontend_root_url"; "label"="DEV If using this app as an API and you have a separate frontend, enter the root of the frontend app (ex: https://localhost:8080/)" }
    @{ "key"="app_redirect_after_login"; "label"="DEV If appConfig.enableAuthRoutes==true, user will be redirected to this url after successful login (ex: https://localhost:8080/auth/sign-in)" }
    @{ "key"="app_redirect_after_logout"; "label"="DEV If appConfig.enableAuthRoutes==true, user will be redirected to this url after successful login (ex: https://localhost:8080/auth/sign-out)" }
    @{ "key"="app_smtp_server"; "label"="DEV SMTP server address (ex: tenant-com.mail.protection.outlook.com)" }
    @{ "key"="app_smtp_sendmail_from_address"; "label"="DEV Default email address to send emails from (ex: noreply@tenant.com)" }
    @{ "key"="app_smtp_sendmail_from_name"; "label"="DEV Default human-readable name that will appear as the sender of emails (ex. Tenant Company)" }
    @{ "key"="app_ssl_path"; "label"="DEV Absolute path to a current cacert.pem file for CURL and OpenSSL extensions (path only)" }
    @{ "key"="app_php_path"; "label"="DEV Absolute path to the PHP executable root directory" }
    @{ "key"="prod_app_root_url"; "label"="PROD Root url of app (ex: https://app.garrettcountymd.gov/)" }
    @{ "key"="prod_app_base_path"; "label"="PROD base url path of app (ex: /api/, Or: / if site is at url root)" }
    @{ "key"="prod_app_frontend_root_url"; "label"="PROD If using this app as an API and you have a separate frontend, enter the root of the frontend app (ex: https://app.garrettcountymd.gov/)" }
    @{ "key"="prod_app_redirect_after_login"; "label"="PROD If appConfig.enableAuthRoutes==true, user will be redirected to this url after successful login (ex: https://app.garrettcountymd.gov/app/auth/sign-in)" }
    @{ "key"="prod_app_redirect_after_logout"; "label"="PROD If appConfig.enableAuthRoutes==true, user will be redirected to this url after successful logout (ex: https://app.garrettcountymd.gov/app/auth/sign-out)" }
    @{ "key"="prod_app_absolute_path"; "label"="PROD Production absolute path to root directory (ex: E:\Web\api)" }
    @{ "key"="prod_app_ssl_path"; "label"="PROD Absolute path to to a current cacert.pem file for CURL and OpenSSL extensions (path only)" }
    @{ "key"="prod_app_php_path"; "label"="PROD Absolute path to the PHP executable root directory" }
)

if ($defineMicrosoftIds -eq 0)
{
    $prompts += @{ "key"="app_microsoft_client_id"; "label"="DEV Microsoft Azure App client id" }
    $prompts += @{ "key"="app_microsoft_client_secret";  "label"="DEV Microsoft Azure App client secret" }
    $prompts += @{ "key"="app_microsoft_tenant";  "label"="DEV Microsoft Azure App tenant" }
    $prompts += @{ "key"="app_microsoft_drive_id";  "label"="DEV Microsoft Azure App drive id" }
    $prompts += @{ "key"="app_microsoft_default_from_address";  "label"="DEV Microsoft Azure App default from address" }

    $prompts += @{ "key"="prod_app_microsoft_client_id";  "label"="PROD Microsoft Azure App client id" }
    $prompts += @{ "key"="prod_app_microsoft_client_secret";  "label"="PROD Microsoft Azure App client secret" }
    $prompts += @{ "key"="prod_app_microsoft_tenant";  "label"="PROD Microsoft Azure App tenant" }
    $prompts += @{ "key"="prod_app_microsoft_drive_id";  "label"="PROD Microsoft Azure App drive id" }
    $prompts += @{ "key"="prod_app_microsoft_default_from_address";  "label"="PROD Microsoft Azure App default from address" }

}


# Loop through each prompt
foreach ($prompt in $prompts) {
    # Prompt the user for input
    $inputValue = Read-Host $prompt.label

    # Store the input in the hashtable
    $inputs[$prompt.key] = $inputValue

}

# Ask the user if they want to edit any value in the hashtable
do {
    # Prompt the user to select which value to edit
    $editPrompt = "Select the number corresponding to the value you want to edit or 0 to confirm all:`n"
    $i = 1
    foreach ($prompt in $prompts) {
        $editPrompt += -join( $i, ". ", $prompt.key, ": ", $inputs[$prompt.key], "`n")
        $i++
    }
    $editPrompt += "0. Done editing"

    $editIndex = Read-Host $editPrompt
    if ($editIndex -ne "0") {
        $editIndex = [int]$editIndex
        if ($editIndex -ge 1 -and $editIndex -le $prompts.Count) {
            $editKey = $prompts[$editIndex - 1].key
            $newValue = Read-Host "Enter the new value for "$editKey
            $inputs[$editKey] = $newValue
        }
        else {
            Write-Host "Invalid selection. Please enter a number between 1 and ${$inputs.Keys.Count} or 0 to exit editing." -ForegroundColor Red
            $editIndex = $false
        }
    }
} while ($editIndex -ne "0")

$app_guid = [guid]::NewGuid().ToString("N");
$app_absolute_path = $path

$replaceInExtensions = '^\.(ini|json|php|config|bat|ps1)$'
$replacementTable = @{
    "{app_guid}" = $app_guid;
    "{app_title}" = $inputs['app_title'];
    "{app_root_url}" = $inputs['app_root_url'].TrimEnd('/\')
    "{app_base_path}" = FormatRelativeUrl -path $inputs['app_base_path'];
    "{app_relative_url}" = FormatRelativeUrl -path $inputs['app_base_path'] -trailingSlash $true -leadingSlash $false;
    "{app_frontend_root_url}" = $inputs['app_frontend_root_url'].TrimEnd('/\')
    "{app_redirect_after_login}" = $inputs['app_redirect_after_login'];
    "{app_redirect_after_logout}" = $inputs['app_redirect_after_logout'];
    "{app_absolute_path}" = $app_absolute_path.TrimEnd('/\')
    "{app_smtp_server}" = $inputs['app_smtp_server'];
    "{app_smtp_sendmail_from_address}" = $inputs['app_smtp_sendmail_from_address'];
    "{app_smtp_sendmail_from_name}" = $inputs['app_smtp_sendmail_from_name'];
    "{app_ssl_path}" = $inputs['app_ssl_path'].TrimEnd('/\')
    "{app_php_path}" = $inputs['app_php_path'].TrimEnd('/\')
    "{app_microsoft_client_id}" = $inputs['app_microsoft_client_id'];
    "{app_microsoft_client_secret}" = $inputs['app_microsoft_client_secret'];
    "{app_microsoft_tenant}" = $inputs['app_microsoft_tenant'];
    "{app_microsoft_drive_id}" = $inputs['app_microsoft_drive_id'];
    "{app_microsoft_default_from_address}" = $inputs['app_microsoft_default_from_address'];
    "{prod_app_root_url}" = $inputs['prod_app_root_url'].TrimEnd('/\')
    "{prod_app_frontend_root_url}" = $inputs['prod_app_frontend_root_url'].TrimEnd('/\')
    "{prod_app_base_path}" = FormatRelativeUrl -path $inputs['prod_app_base_path']
    "{prod_app_relative_url}" = FormatRelativeUrl -path $inputs['prod_app_relative_url'] -trailingSlash $true -leadingSlash $false
    "{prod_app_redirect_after_logout}" = $inputs['prod_app_redirect_after_logout']
    "{prod_app_absolute_path}" = $inputs['prod_app_absolute_path'].TrimEnd('/\')
    "{prod_app_ssl_path}" = $inputs['prod_app_ssl_path'].TrimEnd('/\')
    "{prod_app_php_path}" = $inputs['prod_app_php_path'].TrimEnd('/\')
    "{prod_app_microsoft_client_id}" = $inputs['prod_app_microsoft_client_id']
    "{prod_app_microsoft_client_secret}" = $inputs['prod_app_microsoft_client_secret']
    "{prod_app_microsoft_tenant}" = $inputs['prod_app_microsoft_tenant']
    "{prod_app_microsoft_drive_id}" = $inputs['prod_app_microsoft_drive_id']
    "{prod_app_microsoft_default_from_address}" = $inputs['prod_app_microsoft_default_from_address']
}

foreach ($key in $replacementTable.Keys)
{
    $value = $replacementTable[$key]
    if ($value -eq '' -Or $null -eq $value)
    {
        continue;
    }

    Write-Host "Replace $key in project files" -ForegroundColor Yellow
    foreach ($file in Get-ChildItem -Exclude vendor -Recurse | Where-Object { Select-String $key $_ -Quiet })
    {
        $tmpValue = $value
        #escape backslash in json files
        if ($file.Extension -eq '.json') {
            $tmpValue = $value.Replace("\", "\\")
        }
        if ($file.Extension -match $replaceInExtensions)
        {
            Write-Host "--"$file.FullName
            (Get-Content $file.FullName).Replace($key, $tmpValue) | Set-Content $file.FullName
        }
    }

}
