<?php

class Modules_XveLaravelKit_SshKey
{
    public static function ensure(Modules_XveLaravelKit_DeploySettings $settings)
    {
        $keyDir = $settings->getSshKeyDir();
        $privateKey = $settings->getSshPrivateKeyPath();

        if (file_exists($privateKey)) {
            return;
        }

        $cmd = sprintf(
            'mkdir -p %s && ssh-keygen -t ed25519 -f %s -N "" -C %s 2>&1',
            escapeshellarg($keyDir),
            escapeshellarg($privateKey),
            escapeshellarg('xve-deploy@' . $settings->getDomain()->getDisplayName())
        );

        pm_ApiCli::callSbin('xve-exec.sh', [$cmd]);
    }

    public static function getPublicKey(Modules_XveLaravelKit_DeploySettings $settings)
    {
        self::ensure($settings);

        $pubKeyPath = $settings->getSshPublicKeyPath();
        $fm = new pm_ServerFileManager();

        if ($fm->fileExists($pubKeyPath)) {
            return trim($fm->fileGetContents($pubKeyPath));
        }

        return '';
    }
}
