<?php

namespace Drupal\s3fs\AWS\S3;

use Aws\S3\S3Client;
use Drupal\Core\Database\SchemaObjectExistsException;

/**
 * Class DrupalAdaptor
 *
 * @package   Drupal\s3fs\AWS\S3
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class DrupalAdaptor
{

    /**
     * @var S3Client
     */
    protected $s3Client;

    function __construct(S3Client $s3Client)
    {
        $this->s3Client = $s3Client;
    }

    /**
     * @return S3Client
     */
    public function getS3Client()
    {
        return $this->s3Client;
    }

    /**
     * Refresh the local S3 cache
     *
     * @throws \Exception
     */
    public function refreshCache()
    {
        $config = \Drupal::config('s3fs.settings');

        $s3Config = $config->get('s3');

        // Set up the iterator that will loop over all the objects in the bucket.
        $file_metadata_list = array();
        $iterator_args      = array('Bucket' => $s3Config['bucket']);

        // If the 'prefix' option has been set, retrieve from S3 only those files
        // whose keys begin with the prefix.
        if(!empty($s3Config['prefix']))
        {
            $iterator_args['Prefix'] = $s3Config['prefix'];
        }
        $iterator = $this->s3Client->getListObjectsIterator($iterator_args);
        $iterator->setPageSize(1000);

        // The $folders array is an associative array keyed by folder names, which
        // is constructed as each filename is written to the DB. After all the files
        // are written, the folder names are converted to metadata and written.
        $folders          = array();
        $existing_folders = db_select('file_s3fs', 's')
            ->fields('s', array('uri'))
            ->condition('dir', 1, '=');
        // If a prefix is set, only select folders which start with it.
        if(!empty($s3Config['prefix']))
        {
            $existing_folders = $existing_folders->condition('uri', db_like("s3://{$s3Config['prefix']}") . '%', 'LIKE');
        }
        foreach($existing_folders->execute()->fetchCol(0) as $folder_uri)
        {
            $folders[$folder_uri] = true;
        }

        // Create the temp table, into which all the refreshed data will be written.
        // After the full refresh is complete, the temp table will be swapped in.
        module_load_install('s3fs');
        $schema = s3fs_schema();
        try
        {
            db_create_table('file_s3fs_temp', $schema['file_s3fs']);
            // db_create_table() ignores the 'collation' option. >_<
            db_query("ALTER TABLE {file_s3fs_temp} CONVERT TO CHARACTER SET utf8 COLLATE utf8_bin");
        }
        catch(SchemaObjectExistsException $e)
        {
            // The table already exists, so truncate it.
            db_truncate('file_s3fs_temp')->execute();
        }

        // Set up an event listener to consume each page of results before the next
        // request is made.
        $dispatcher = $iterator->getEventDispatcher();
        $dispatcher->addListener('resource_iterator.before_send', function ($event) use (&$file_metadata_list, &$folders)
        {
            $this->writeMetadata($file_metadata_list, $folders);
        });

        foreach($iterator as $s3_metadata)
        {
            $uri = "s3://{$s3_metadata['Key']}";

            if($uri[strlen($uri) - 1] == '/')
            {
                // Treat objects in S3 whose filenames end in a '/' as folders.
                // But we don't store the '/' itself as part of the folder's metadata.
                $folders[rtrim($uri, '/')] = true;
            }
            else
            {
                // Treat the rest of the files normally.
                $file_metadata_list[] = $this->convertMetadata($uri, $s3_metadata);
            }
        }
        // Push the last page of metadata to the DB. The event listener doesn't fire
        // after the last page is done, so we have to do it manually.
        $this->writeMetadata($file_metadata_list, $folders);

        // Now that the $folders array contains all the ancestors of every file in
        // the cache, as well as the existing folders from before the refresh,
        // write those folders to the temp table.
        if($folders)
        {
            $insert_query = db_insert('file_s3fs_temp')
                ->fields(array('uri', 'filesize', 'timestamp', 'dir', 'mode', 'uid'));
            foreach($folders as $folder_uri => $ph)
            {
                // If it's set, exclude any folders which don't match the prefix.
                if(!empty($s3Config['prefix']) && strpos($folder_uri, "s3://{$s3Config['prefix']}") === false)
                {
                    continue;
                }
                $metadata = $this->convertMetadata($folder_uri, array());
                $insert_query->values($metadata);
            }
            // TODO: If this throws an integrity constraint violation, then the user's
            // S3 bucket has objects that represent folders using a different scheme
            // then the one we account for above. The best solution I can think of is
            // to convert any "files" in file_s3fs_temp which match an entry in the
            // $folders array (which would have been added in $this->writeMetadata())
            // to directories.
            $insert_query->execute();
        }

        // We're done, so replace data in the real table with data from the temp table.
        if(empty($s3Config['prefix']))
        {
            // If this isn't a partial reresh, we can do a full table swap.
            db_rename_table('file_s3fs', 'file_s3fs_old');
            db_rename_table('file_s3fs_temp', 'file_s3fs');
            db_drop_table('file_s3fs_old');
        }
        else
        {
            // This is a partial refresh, so we can't just replace the file_s3fs table.
            // We wrap the whole thing in a transacation so that we can return the
            // database to its original state in case anything goes wrong.
            $transaction = db_transaction();
            try
            {
                $rows_to_copy = db_select('file_s3fs_temp', 's')
                    ->fields('s', array('uri', 'filesize', 'timestamp', 'dir', 'mode', 'uid'));

                // Delete from file_s3fs only those rows which match the prefix.
                $delete_query = db_delete('file_s3fs')
                    ->condition('uri', db_like("s3://{$s3Config['prefix']}") . '%', 'LIKE')
                    ->execute();

                // Copy the contents of file_s3fs_temp (which all have the prefix) into
                // file_s3fs (which was just cleared of all contents with the prefix).
                db_insert('file_s3fs')
                    ->from($rows_to_copy)
                    ->execute();
                db_drop_table('file_s3fs_temp');
            }
            catch(\Exception $e)
            {
                $transaction->rollback();
                watchdog_exception('S3 File System', $e);
                drupal_set_message(t('S3 File System cache refresh failed. Please see log messages for details.'), 'error');

                return;
            }
            // Destroying the transaction variable is the only way to explicitly commit.
            unset($transaction);
        }

        if(empty($s3Config['prefix']))
        {
            drupal_set_message(t('S3 File System cache refreshed.'));
        }
        else
        {
            drupal_set_message(t('Files in the S3 File System cache with prefix %prefix have been refreshed.', array('%prefix' => $s3Config['prefix'])));
        }
    }

    /**
     * Writes metadata to the temp table in the database.
     *
     * @param array $file_metadata_list
     *   An array passed by reference, which contains the current page of file
     *   metadata. This function empties out $file_metadata_list at the end.
     * @param array $folders
     *   An associative array keyed by folder name, which is populated with the
     *   ancestor folders of each file in $file_metadata_list.
     */
    public function writeMetadata(&$file_metadata_list, &$folders)
    {
        if($file_metadata_list)
        {
            $insert_query = db_insert('file_s3fs_temp')
                ->fields(array('uri', 'filesize', 'timestamp', 'dir', 'mode', 'uid'));
            foreach($file_metadata_list as $metadata)
            {
                // Write the file metadata to the DB.
                $insert_query->values($metadata);

                // Add the ancestor folders of this file to the $folders array.
                $uri = dirname($metadata['uri']);
                // Loop through each ancestor folder until we get to 's3://'.
                while(strlen($uri) > 5)
                {
                    $folders[$uri] = true;
                    $uri           = dirname($uri);
                }
            }
            $insert_query->execute();
        }

        // Empty out the file array, so it can be re-filled by the next request.
        $file_metadata_list = array();
    }

    /**
     * Convert file metadata returned from S3 into a metadata cache array.
     *
     * @param string $uri
     *   A string containing the uri of the resource to check.
     * @param array  $s3_metadata
     *   An array containing the collective metadata for the object in S3.
     *   The caller may send an empty array here to indicate that the returned
     *   metadata should represent a directory.
     *
     * @return array
     *   An array containing metadata formatted for the file metadata cache.
     */
    public function convertMetadata($uri, array $s3_metadata = array())
    {
        $metadata = array('uri' => $uri);

        if(!count($s3_metadata))
        {
            // The caller wants directory metadata, so invent some.
            $metadata['dir']       = 1;
            $metadata['filesize']  = 0;
            $metadata['timestamp'] = time();
            $metadata['uid']       = 'S3 File System';
            // The posix S_IFDIR flag.
            $metadata['mode'] = 0040000;
        }
        else
        {
            // The caller sent us some actual metadata, so this must be a file.
            if(isset($s3_metadata['Size']))
            {
                $metadata['filesize'] = $s3_metadata['Size'];
            }
            if(isset($s3_metadata['LastModified']))
            {
                $metadata['timestamp'] = date('U', strtotime($s3_metadata['LastModified']));
            }
            if(isset($s3_metadata['Owner']['ID']))
            {
                $metadata['uid'] = $s3_metadata['Owner']['ID'];
            }
            $metadata['dir'] = 0;
            // The S_IFREG posix flag.
            $metadata['mode'] = 0100000;
        }
        // Everything is writeable.
        $metadata['mode'] |= 0777;

        return $metadata;
    }

} 
