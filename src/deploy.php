<?php
require_once '../vendor/autoload.php';
require_once __DIR__ . '/Servers.php';

use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;


$last_deploy_commit_file = __DIR__ . '/../commits/last_deploy_commit.txt';

function runLocalCommand($command) {
    exec($command, $output, $returnVar);
    if ($returnVar !== 0) {
        throw new Exception("Error executing command: $command\n" . implode("\n", $output));
    }
    return $output;
}

foreach ($serversConfig as $key => $server) {
    try {
        echo "Establishing SFTP connection to {$server['host']}...\n";
        $sftp = new SFTP($server['host'], $server['port']);
        $ssh = new SSH2($server['host'], $server['port']);


        if (isset($server['privateKey']) && !empty($server['privateKey'])) {
            $publicKey = PublicKeyLoader::load(file_get_contents($server['privateKey']));
            if (!$sftp->login($server['username'], $publicKey) || !$ssh->login($server['username'], $publicKey)) {
                throw new Exception("Failed to log in SFTP with key.");
            }
        } elseif (isset($server['pass']) && !empty($server['pass'])) {
            if (!$sftp->login($server['username'], $server['pass']) || !$ssh->login($server['username'], $server['pass'])) {
                throw new Exception("Failed to log in SFTP with password.");
            }
        }


        $lastDeployCommit = file_exists($last_deploy_commit_file) ? json_decode(file_get_contents($last_deploy_commit_file), true) : null;


        echo "Updating local repository...\n";
        $localDirectory = escapeshellarg($local_directory);
        runLocalCommand("git -C $localDirectory pull");


        echo "Generating list of modified files...\n";
        $changesFile = __DIR__ . '/../commits/changes.txt';
        $commitRange = isset($lastDeployCommit[$server['host']]) && !empty($lastDeployCommit[$server['host']]) ? "{$lastDeployCommit[$server['host']]} HEAD" : '';
        $command = $commitRange ? "git -C $localDirectory diff --name-status $commitRange > ".escapeshellarg($changesFile)."" : "git -C $localDirectory ls-files > ".escapeshellarg($changesFile)."";

        runLocalCommand($command);


        $changes = file($changesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $ignoreFiles = array('.gitignore', '.gitattributes', '.gitmodules', 'node_modules');
        foreach($changes as $key => $file){
            if(in_array($file, $ignoreFiles)){
                unset($changes[$key]);
            }
        }

        if (empty($changes)) {
            echo "No changes to deploy.\n";
            continue;
        }


        foreach ($changes as $file) {
            $status = false;
            $archive = preg_split('/\s+/', $file, 2);
            if(count($archive) >= 2){
                if(in_array($archive[0],array('A', 'D', 'M'))){
                    $status = $archive[0]; //Save the status
                    unset($archive[0]);
                }
                $file = implode(" ",$archive); 
            }else{
                $file = implode(" ",$archive);
            }

            $localPath = $local_directory.'/'.$file;
            $remotePath = "{$server['remote_directory']}$file";

            if ($status === 'D') {
                if ($sftp->file_exists($remotePath)) {
                    $sftp->delete($remotePath);
                    echo "Deleted: $remotePath\n";
                }
            } else {
                $remoteDir = dirname($remotePath);
                if (!$sftp->is_dir($remoteDir)) {
                    $sftp->mkdir($remoteDir, -1, true);
                }
                $sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE);
                echo "Uploaded: $file\n";
            }
        }


        $currentCommit = trim(runLocalCommand("git -C $localDirectory rev-parse HEAD")[0]);
        $lastDeployCommit[$server['host']] = $currentCommit;
        file_put_contents($last_deploy_commit_file, json_encode($lastDeployCommit));
        echo "Deployment completed for {$server['host']}.\n";

    } catch (Exception $e) {
        echo "Error during deployment: " . $e->getMessage() . "\n";
    }
}
