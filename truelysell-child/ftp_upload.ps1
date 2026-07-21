$ftpHost = "65.181.120.76"
$ftpUser = "Lakeisha@tb02ybvv7a.wpdns.site"
$ftpPass = "Shoaibboo_12"
$credentials = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)

function Upload-FtpFile($localPath, $remotePath) {
    $request = [System.Net.FtpWebRequest]::Create("ftp://$ftpHost/$remotePath")
    $request.Credentials = $credentials
    $request.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    
    try {
        $fileBytes = [System.IO.File]::ReadAllBytes($localPath)
        $request.ContentLength = $fileBytes.Length
        $requestStream = $request.GetRequestStream()
        $requestStream.Write($fileBytes, 0, $fileBytes.Length)
        $requestStream.Close()
        
        $response = $request.GetResponse()
        $response.Close()
        Write-Output "Successfully uploaded $localPath to $remotePath"
    } catch {
        Write-Error "Failed to upload $localPath : $_"
    }
}

Upload-FtpFile "d:\kidsverse website\mounting mouse\truelysell-child\functions.php" "wp-content/themes/truelysell-child/functions.php"
Upload-FtpFile "d:\kidsverse website\mounting mouse\truelysell-child\template-parts\template-dashboard-customer.php" "wp-content/themes/truelysell-child/template-parts/template-dashboard-customer.php"
Upload-FtpFile "d:\kidsverse website\mounting mouse\template-parts\dashboard\dashboard-customer.php" "wp-content/themes/truelysell/template-parts/dashboard/dashboard-customer.php"
Upload-FtpFile "d:\kidsverse website\mounting mouse\truelysell-child\truelysell-core\account\logged_section.php" "wp-content/themes/truelysell-child/truelysell-core/account/logged_section.php"
Upload-FtpFile "d:\kidsverse website\mounting mouse\template-parts\provider-details.php" "wp-content/themes/truelysell/template-parts/provider-details.php"
