<?php

class Modules_XveLaravelKit_SshKey
{
    // GitHub SSH host keys, verified against
    // https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints
    const GITHUB_KNOWN_HOSTS =
        "github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl\n" .
        "github.com ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBEmKSENjQEezOmxkZMy7opKgwFB9nkt5YRrYMjNuG5N87uRgg6CLrbo5wAdT/y6v0mKV0U2w0WZ2YB/++Tpockg=\n" .
        "github.com ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQCj7ndNxQowgcQnjshcLrqPEiiphnt+VTTvDP6mHBL9j1aNUkY4Ue1gvwnGLVlOhGeYrnZaMgRK6+PKCUXaDbC7qtbW8gIkhL7aGCsOr/C56SJMy/BCZfxd1nWzAOxSDPgVsmerOBYfNqltV9/hWCqBywINIR+5dIg6JTJ72pcEpEjcYgXkE2YEFXV1JHnsKgbLWNlhScqb2UmyRkQyytRLtL+38TGxkxCflmO+5Z8CSSNY7GidjMIZ7Q4zMjA2n1nGrlTDkzwDCsw+wqFPGQA179cnfGWOWRVruj16z6XyvxvjJwbz0wQZ75XK5tKSb7FNyeIEs4TT4jk+S4dhPeAUC5y+bDYirYgM4GC7uEnztnZyaVWQ7B381AK4Qdrwt51ZqExKbQpTUNn+EjqoTwvqNj4kqx5QUCI0ThS/YkOxJCXmPUWZbhjpCg56i+2aB6CmK2JGhn57K5mj0MNdBXA4/WnwH6XoPWJzK5Nyu2zB3nAZp+S5hpQs+p1vN1/wsjk=\n";

    public static function getKnownHostsPath(Modules_XveLaravelKit_DeploySettings $settings)
    {
        return $settings->getSshKeyDir() . '/known_hosts';
    }

    public static function ensureKnownHosts(Modules_XveLaravelKit_DeploySettings $settings)
    {
        self::ensure($settings); // guarantees the key dir exists
        $path = self::getKnownHostsPath($settings);
        $fm = new pm_ServerFileManager();
        if (!$fm->fileExists($path) || trim($fm->fileGetContents($path)) === '') {
            $fm->filePutContents($path, self::GITHUB_KNOWN_HOSTS);
        }
        return $path;
    }

    public static function ensure(Modules_XveLaravelKit_DeploySettings $settings)
    {
        $keyDir = $settings->getSshKeyDir();
        $privateKey = $settings->getSshPrivateKeyPath();

        if (file_exists($privateKey)) {
            return;
        }

        pm_ApiCli::callSbin('xve-exec.sh', [
            'ssh-keygen',
            $keyDir,
            $privateKey,
            'xve-deploy@' . $settings->getDomain()->getDisplayName(),
        ]);
    }

    public static function remove(Modules_XveLaravelKit_DeploySettings $settings)
    {
        $keyDir = $settings->getSshKeyDir();
        if (is_dir($keyDir)) {
            pm_ApiCli::callSbin('xve-exec.sh', ['rm-rf', $keyDir]);
        }
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
