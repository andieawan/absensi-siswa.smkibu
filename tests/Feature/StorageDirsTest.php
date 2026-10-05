<?php

namespace Tests\Feature;

use Tests\TestCase;

class StorageDirsTest extends TestCase
{
    public function test_missing_session_directory_is_recreated_on_boot(): void
    {
        $dir = storage_path('framework/sessions');
        $keep = glob($dir.'/*') ?: [];
        array_map('unlink', array_filter($keep, 'is_file'));
        @unlink($dir.'/.gitignore');
        @rmdir($dir);
        $this->assertDirectoryDoesNotExist($dir);
        (new \App\Providers\AppServiceProvider($this->app))->register();
        $this->assertDirectoryExists($dir);
        file_put_contents($dir.'/.gitignore', "*\n!.gitignore\n");
    }
}
