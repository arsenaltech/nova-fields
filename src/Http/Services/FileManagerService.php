<?php

namespace R64\NovaFields\Http\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use R64\NovaFields\Events\FileRemoved;
use R64\NovaFields\Events\FileUploaded;
use R64\NovaFields\Events\FolderRemoved;
use R64\NovaFields\Events\FolderUploaded;
use R64\NovaFields\Http\Exceptions\InvalidConfig;
use InvalidArgumentException;

class FileManagerService
{
    use GetFiles;

    /**
     * @var mixed
     */
    protected $storage;

    /**
     * @var mixed
     */
    protected $disk;

    /**
     * @var mixed
     */
    protected $currentPath;

    /**
     * @var mixed
     */
    protected $exceptFiles;

    /**
     * @var mixed
     */
    protected $exceptFolders;

    /**
     * @var mixed
     */
    protected $exceptExtensions;

    /**
     * @var AbstractNamingStrategy
     */
    protected $namingStrategy;

    /**
     * @param Storage $storage
     */
    public function __construct()
    {
        $this->disk = config('filemanager.disk', 'public');

        $this->exceptFiles = collect([]);
        $this->exceptFolders = collect([]);
        $this->exceptExtensions = collect([]);
        $this->globalFilter = null;

        try {
            $this->storage = Storage::disk($this->disk);
        } catch (InvalidArgumentException $e) {
            throw InvalidConfig::driverNotSupported();
        }

        $this->namingStrategy = app()->makeWith(
            config('filemanager.naming', DefaultNamingStrategy::class),
            ['storage' => $this->storage]
        );
    }

    /**
     * Get ajax request to load files and folders.
     *
     * @param Request $request
     *
     * @return json
     */
    public function ajaxGetFilesAndFolders(Request $request)
    {
        $folder = $this->cleanSlashes($request->get('folder'));
        $isMultipleSelection = $this->cleanSlashes($request->get('isMultipleSelection'));

        if (! $this->folderExists($folder)) {
            $folder = '/';
        }
        $this->setRelativePath($folder);

        $order = $request->get('sort');
        if (! $order) {
            $order = config('filemanager.order', 'mime');
        }

        $filter = $request->get('filter', config('filemanager.filter', false));

        $files = $this->getFiles($folder, $order, $filter);

        $filters = $this->getAvailableFilters($files);

        $parent = (object) [];
        if ($files->count() > 0) {
            $folders = $files->filter(function ($file) {
                return $file->type == 'dir';
            });

            if ($folder !== '/') {
                $parent = $this->generateParent($folder);
            }
        }

        return response()->json([
            'files'   => $files,
            'path'    => $this->getPaths($folder),
            'filters' => $filters,
            'buttons' => $this->getButtons($isMultipleSelection),
            'parent'  => $parent,
        ]);
    }

    /**
     *  Create a folder on current path.
     *
     * @param $folder
     * @param $path
     *
     * @return  json
     */
    public function createFolderOnPath($folder, $currentFolder,$isCreateSameName)
    {
        $folder = $this->fixDirname($this->fixFilename($folder));
        $folder = str_replace(" ","_",$folder);
        $path = $currentFolder.'/'.$folder;
        if($isCreateSameName){
            $count = 1;
            while($count > 0){
                $temp_path = $path.'_'.$count;
                if ($this->storage->has($temp_path)) {
                    $count++;
                    continue;
                }
                $path = $temp_path;
                break;
            }
        }else{
            if ($this->storage->has($path)) {
                return response()->json(['error' => __('The folder exist in current path')]);
            }
        }

        if ($this->storage->makeDirectory($path)) {
            // Invalidate the cached listing for the parent folder so the new folder appears immediately
            $this->forgetFolderCache($currentFolder);
            return response()->json(true);
        } else {
            return response()->json(false);
        }
    }

    /**
     * Invalidate the filemanager listing cache for a given folder.
     * Only acts when caching is enabled (filemanager.cache is not false).
     *
     * @param string $folder
     */
    private function forgetFolderCache($folder)
    {
        if (config('filemanager.cache', false) !== false) {
            $folder = $this->normalizePath($folder);
            $cacheKey = 'filemanager_' . md5($this->disk . '_' . $folder);
            Cache::forget($cacheKey);
        }
    }

    /**
     * Normalize a dirname() result: maps '.' (returned for root-level paths) to '/'.
     *
     * @param string $dir
     * @return string
     */
    private function normalizeDirname($dir): string
    {
        return ($dir === '.' || $dir === '') ? '/' : $dir;
    }

    /**
     * Removes a directory.
     *
     * @param string $path
     *
     * @return  json
     */
    public function deleteDirectory($path)
    {
        if ($this->storage->deleteDirectory($path)) {
            event(new FolderRemoved($this->storage, $path));
            // Invalidate cache for the parent folder (the deleted folder disappears from it)
            $this->forgetFolderCache($this->normalizeDirname(dirname($path)));

            return response()->json(['success' => true, 'data' => []]);
        } else {
            return response()->json(['success' => false, 'data' => "Something is wrong to delete folder. please try again later."]);
        }
    }

    /**
     * Upload a file on current folder.
     *
     * @param $file
     * @param $currentFolder
     *
     * @return  json
     */
    public function uploadFile($file, $currentFolder, $visibility, $uploadingFolder = false, array $rules = [])
    {
        if (request()->has('vaporFile')) {
            $vaporFile = request()->input('vaporFile');
            $tempKey = $vaporFile['key'];
            $fileName = $vaporFile['filename'];
            $fileName = str_replace(" ", "_", $fileName);
            
            $normalizedFolder = $this->normalizePath($currentFolder);
            $targetPath = $normalizedFolder ? $normalizedFolder . '/' . $fileName : $fileName;
            
            try {
                $vaporDisk = Storage::build([
                    'driver' => 's3',
                    'key' => env('AWS_ACCESS_KEY_ID') ?: env('AWS_FILE_MANAGER_ACCESS_KEY_ID'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY') ?: env('AWS_FILE_MANAGER_SECRET_ACCESS_KEY'),
                    'region' => env('AWS_DEFAULT_REGION') ?: env('AWS_FILE_MANAGER_DEFAULT_REGION'),
                    'bucket' => env('AWS_BUCKET') ?: env('AWS_FILE_MANAGER_BUCKET'),
                ]);

                $copied = false;
                $stream = $vaporDisk->readStream($tempKey);

                if ($stream) {
                    $copied = $this->storage->writeStream($targetPath, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                if ($copied) {
                    $vaporDisk->delete($tempKey);
                    
                    $this->setVisibility($currentFolder, $fileName, $visibility);

                    $fullPath = $normalizedFolder ? $normalizedFolder . '/' . $fileName : $fileName;

                    if (! $uploadingFolder) {
                        try {
                            $this->checkJobs($this->storage, $fullPath);
                            event(new FileUploaded($this->storage, $fullPath));
                        } catch (\Exception $e) {
                            \Log::error("Post-upload tasks failed for {$fullPath}: " . $e->getMessage());
                        }
                    }

                    $this->forgetFolderCache($normalizedFolder);

                    return response()->json(['success' => true, 'name' => $fileName]);
                } else {
                    $vaporDisk->delete($tempKey);
                    \Log::warning("S3 copy/transfer returned false: from {$tempKey} to {$targetPath}");
                    return response()->json(['success' => false]);
                }
            } catch (\Exception $e) {
                if (isset($vaporDisk)) {
                    try {
                        $vaporDisk->delete($tempKey);
                    } catch (\Exception $ex) {}
                }
                \Log::error("Failed to copy Vapor uploaded file from {$tempKey} to {$targetPath}: " . $e->getMessage());
                return response()->json(['success' => false, 'error' => $e->getMessage()]);
            }
        }

        if (count($rules) > 0) {
            $pases = Validator::make(['file' => $file], [
                'file' => $rules,
            ])->validate();
        }

        $fileName = $this->namingStrategy->name($currentFolder, $file);
        $fileName = str_replace(" ","_",$fileName);
        if ($this->storage->putFileAs($currentFolder, $file, $fileName)) {
            $this->setVisibility($currentFolder, $fileName, $visibility);

            $normalizedFolder = $this->normalizePath($currentFolder);
            $fullPath = $normalizedFolder ? $normalizedFolder . '/' . $fileName : $fileName;

            if (! $uploadingFolder) {
                try {
                    $this->checkJobs($this->storage, $fullPath);
                    event(new FileUploaded($this->storage, $fullPath));
                } catch (\Exception $e) {
                    // Log error but continue to clear cache since file was uploaded
                    \Log::error("Post-upload tasks failed for {$fullPath}: " . $e->getMessage());
                }
            }

            // Invalidate cache for the folder the file was uploaded into
            $this->forgetFolderCache($normalizedFolder);

            return response()->json(['success' => true, 'name' => $fileName]);
        } else {
            return response()->json(['success' => false]);
        }
    }

    /**
     * @param $file
     * @return mixed
     */
    public function downloadFile($file)
    {
        if (! config('filemanager.buttons.download_file')) {
            return response()->json(['success' => false, 'message' => 'File not available for Download'], 403);
        }

        if ($this->storage->has($file)) {
            return $this->storage->download($file);
        } else {
            return response()->json(['success' => false, 'message' => 'File not found'], 404);
        }
    }

    /**
     * Get info of file normalized.
     *
     * @param $file
     *
     * @return  json
     */
    public function getFileInfo($file)
    {
        $fullPath = $this->storage->path($file);
        try {
            $info = new NormalizeFile($this->storage, $fullPath, $file);

            return response()->json($info->toArray());
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }

    /**
     * Get info of file as Array.
     *
     * @param $file
     *
     * @return  json
     */
    public function getFileInfoAsArray($file)
    {
        if (! $this->storage->exists($file)) {
            return [];
        }

        $fullPath = $this->storage->path($file);

        $info = new NormalizeFile($this->storage, $fullPath, $file);

        return $info->toArray();
    }

    /**
     * Remove a file from storage.
     *
     * @param string $file
     * @param string $type
     *
     * @return  json
     */
    public function removeFile($file)
    {
        if ($this->storage->delete($file)) {
            event(new FileRemoved($this->storage, $file));
            // Invalidate cache for the containing folder
            $this->forgetFolderCache($this->normalizeDirname(dirname($file)));

            return response()->json(['success' => true, 'data' => []]);
        } else {
            return response()->json(['success' => false, 'error' => "Something is wrong to delete file. please try again later."]);
        }
    }

    /**
     * @param $file
     */
    public function renameFile($file, $newName)
    {
        $path = str_replace(basename($file), '', $file);

        try {
            if ($this->storage->move($file, $path.$newName)) {
                $fullPath = $this->storage->path($path.$newName);
                $splitOld = explode("/",$file);
                $oldFileName = array_pop($splitOld);
                $oldPath = implode("/",$splitOld);
                Artisan::call("rename:catalog-path", ['old_path' => $oldPath,'new_path'=> $path,'files' => [$newName],'renameType' => 'file_name','old_file_name' => [$oldFileName]]);
                // Invalidate cache for the containing folder (file name changed)
                $this->forgetFolderCache($this->normalizeDirname(rtrim($path, '/')));
                $info = new NormalizeFile($this->storage, $fullPath, $path.$newName);

                return response()->json(['success' => true, 'data' => $info->toArray()]);
            }

            return response()->json(['success' => false, 'error' => "Something is wrong to rename file name."]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function renameDirectory($dir, $newName)
    {
        try {
            $path = str_replace(basename($dir), '', $dir);
            $newDir = $path.$newName;
            if ($this->storage->exists($newDir)) {
                return response()->json(['success' => false, 'error' => "The folder exist in current path."]);
            }

            $this->storage->makeDirectory($newDir);

            $files = $this->storage->files($dir);
            $directories = $this->storage->directories($dir);

            $dirNameLength = strlen($dir);

            foreach ($directories as $subDir) {
                $subDirName = substr($dir, $dirNameLength);
                array_push($files, ...$this->storage->files($subDir));

                if (! Storage::exists($newDir.$subDirName)) {
                    $this->storage->makeDirectory($newDir.$subDirName);
                }
            }

            $copiedFileCount = 0;

            foreach ($files as $file) {
                $filename = substr($file, $dirNameLength);
                $this->storage->copy($file, $newDir.$filename) === true ? $copiedFileCount++ : null;
            }

            if ($copiedFileCount === count($files)) {
                $allfiles = $this->storage->allFiles($dir);
                foreach ($allfiles as $files){
                    $splitFiles = explode("/",$files);
                    $filename = array_pop($splitFiles);
                    $oldDir = implode("/",$splitFiles);
                    $newSubDir = str_replace($dir,$newDir,$oldDir);
                    Artisan::call("rename:catalog-path", ['old_path' => $oldDir,'new_path'=> $newSubDir,'files' => [$filename]]);
                }
                $this->storage->deleteDirectory($dir);
            }

            // Invalidate cache for the parent folder (old name gone, new name appears)
            $this->forgetFolderCache($this->normalizeDirname(rtrim($path, '/')));

            $fullPath = $this->storage->path($newDir);

            $info = new NormalizeFile($this->storage, $fullPath, $newDir);

            return response()->json(['success' => true, 'data' => $info->toArray()]);
        }catch (\Exception $e){
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Move file.
     *
     * @param   string  $oldPath
     * @param   string  $newPath
     *
     * @return  json
     */
    public function moveFile($oldPath, $newPath)
    {
        if ($this->storage->move($oldPath, $newPath)) {
            $splitOld = explode("/",$oldPath);
            array_pop($splitOld);
            $old = implode("/",$splitOld);
            $splitNew = explode("/",$newPath);
            $fileName = array_pop($splitNew);
            $new = implode("/",$splitNew);
            $fullPath = $this->storage->path($newPath);
            // Invalidate both source and destination folder caches
            $this->forgetFolderCache($old ?: '/');
            $this->forgetFolderCache($new ?: '/');
            Artisan::call("rename:catalog-path", ['old_path' => $old,'new_path'=> $new,'files' => [$fileName]]);
            return response()->json(['success' => true]);
        }
        return response()->json(false);
    }

    /**
     * Move Folder.
     *
     * @param   string  $oldPath
     * @param   string  $newPath
     *
     * @return  json
     */
    public function moveFolder($oldPath, $newPath)
    {
        $dir = $oldPath;
        $newDir = $newPath;
        $splitOldPath = explode("/",$oldPath);
        $folderName = array_pop($splitOldPath);
        $newDir = $newDir != '/' ? $newDir.'/'.$folderName : $folderName;
        $this->storage->makeDirectory($newDir);

        $files = $this->storage->files($dir);
        $directories = $this->storage->directories($dir);
        $dirNameLength = strlen($dir);
        foreach ($directories as $subDir) {
            $subDirName = substr($dir, $dirNameLength);
            array_push($files, ...$this->storage->files($subDir));

            if (! Storage::exists($newDir.$subDirName)) {
                $this->storage->makeDirectory($newDir.$subDirName);
            }
        }

        $copiedFileCount = 0;
        foreach ($files as $file) {
            $filepath = substr($file, $dirNameLength);
            $splitOld = explode("/",$file);
            array_pop($splitOld);
            $old = implode("/",$splitOld);
            $splitNew = explode("/",$filepath);
            $filename = array_pop($splitNew);
            $new = '';
            $new = implode("/",$splitNew);
            if($new != ""){
                $new = $newDir. ($new[0] != '/' ? '/'.$new : $new);
            }else{
                $new = $newDir;
            }
            if($this->storage->copy($file, $newDir.$filepath) === true){
                $copiedFileCount++;
                Artisan::call("rename:catalog-path", ['old_path' => $old,'new_path'=> $new,'files' => [$filename]]);
            }
        }
        if ($copiedFileCount === count($files)) {
            $this->storage->deleteDirectory($dir);
        }

        // Invalidate cache for old parent and new destination folder
        $oldParent = implode('/', $splitOldPath) ?: '/';
        $this->forgetFolderCache($oldParent);
        $this->forgetFolderCache($newPath ?: '/');

        $fullPath = $this->storage->path($newDir);
        $info = new NormalizeFile($this->storage, $fullPath, $newDir);

        if(!empty($info->toArray())) {
            return response()->json(['success' => true]);
        }

        return response()->json(false);
    }

    /**
     * Folder uploaded event.
     *
     * @param   string  $path
     *
     * @return  json
     */
    public function folderUploadedEvent($path)
    {
        event(new FolderUploaded($this->storage, $path));

        // Invalidate cache for the parent folder so the newly uploaded folder appears
        $this->forgetFolderCache($this->normalizeDirname(dirname($path)));

        return response()->json(['success' => true]);
    }

    /**
     * @param $folder
     */
    private function folderExists($folder)
    {
        // Root always exists
        if (!$folder || $folder === '/' || $folder === '') {
            return true;
        }

        // Use storage->exists() for a direct HEAD check (works reliably on S3 for
        // newly created empty folders without doing a full directory listing).
        // S3 folders are zero-byte objects with a trailing slash.
        $normalized = rtrim($folder, '/') . '/';
        if ($this->storage->exists($normalized)) {
            return true;
        }

        // Fallback: check via directories() for disks that don't use trailing-slash markers
        try {
            $directories = $this->storage->directories(dirname($folder));
            $directories = collect($directories)->map(fn ($d) => basename($d));
            return in_array(basename($folder), $directories->toArray());
        } catch (\Exception $e) {
            return true; // On error, allow the folder — getFiles() will return empty if it truly doesn't exist
        }
    }

    /**
     * Set visibility to public.
     *
     * @param $folder
     * @param $file
     */
    private function setVisibility($folder, $file, $visibility)
    {
        if ($folder != '/') {
            $folder .= '/';
        }
        $this->storage->setVisibility($folder.$file, $visibility);
    }

    /**
     * @param $files
     */
    private function getAvailableFilters($files)
    {
        $filters = config('filemanager.filters', []);
        if (count($filters) > 0) {
            return collect($filters)->filter(function ($extensions) use ($files) {
                foreach ($files as $file) {
                    if (in_array($file->ext, $extensions)) {
                        return true;
                    }
                }

                return false;
            })->toArray();
        }

        return [];
    }

    private function getButtons($isMultipleSelection)
    {
        $isMultipleSelection = filter_var($isMultipleSelection, FILTER_VALIDATE_BOOLEAN);
        Config::set('filemanager.buttons.select_multiple',$isMultipleSelection);
        return config('filemanager.buttons', [
            'create_folder'   => true,
            'upload_button'   => true,
            'select_multiple' => $isMultipleSelection,
            'upload_drag'     => true,
            'rename_folder'   => true,
            'delete_folder'   => true,
            'rename_file'     => true,
            'delete_file'     => true,
        ]);
    }

    /**
     * @param $currentPath
     * @param $fileName
     */
    private function checkJobs($storage, $filePath)
    {
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        //$availableJobs
        $availableJobs = collect(config('filemanager.jobs', []));

        if (count($availableJobs)) {
            // Filters
            $filters = config('filemanager.filters', []);
            $filters = array_change_key_case($filters);

            $find = collect($filters)->filter(function ($extensions, $key) use ($ext) {
                if (in_array($ext, $extensions)) {
                    return true;
                }
            });

            $filterFind = array_key_first($find->toArray());

            if ($jobClass = $availableJobs->get($filterFind)) {
                $job = new $jobClass($storage, $filePath);

                if ($customQueue = config('filemanager.queue_name')) {
                    $job->onQueue($customQueue);
                }

                app(Dispatcher::class)->dispatch($job);
            }
        }
    }
}
