# gf PowerShell tab completion
#
# Install (choose one):
#   1. Add this line to your PowerShell $PROFILE:
#        vendor\bin\gf completion:powershell | Out-String | Invoke-Expression
#   2. Or save this output to a file and dot-source it from $PROFILE:
#        vendor\bin\gf completion:powershell > ~\gf-completion.ps1
#        . ~\gf-completion.ps1
#
# Bridges PowerShell's argument completer to symfony/console's `_complete`
# machinery (bash output format), so dynamic suggestions — including the
# application's CLI routes for `gf cli` — work the same as in bash/zsh/fish.

Register-ArgumentCompleter -Native -CommandName 'gf', 'gf.bat' -ScriptBlock {
    param($wordToComplete, $commandAst, $cursorPosition)

    $elements = @($commandAst.CommandElements | ForEach-Object { $_.Extent.Text.Trim("'`"") })
    $current = $elements.Count - 1
    if ($cursorPosition -gt $commandAst.Extent.EndOffset) {
        # cursor is past the last typed word: completing a new, empty word
        $current++
    }

    $completeArgs = @('_complete', '--no-interaction', '-sbash', "-c$current", '-a{{API_VERSION}}')
    foreach ($element in $elements) {
        # empty values are ignored (same as symfony's bash bridge); -c handles the position
        if ($element -ne '') {
            $completeArgs += "-i$element"
        }
    }

    & $elements[0] @completeArgs 2>$null |
        Where-Object { $_ -ne '' } |
        ForEach-Object {
            $value = ($_ -split "`t")[0]
            [System.Management.Automation.CompletionResult]::new($value, $value, 'ParameterValue', $value)
        }
}
