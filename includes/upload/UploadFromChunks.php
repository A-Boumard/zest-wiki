<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\WebRequestUpload;
use MediaWiki\Status\Status;
use MediaWiki\User\User;

/**
 * Backend for uploading files from chunks.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Upload
 */

/**
 * Implements uploading from chunks
 *
 * @ingroup Upload
 * @author Michael Dale
 */
class UploadFromChunks extends UploadFromFile {
	/** @var LocalRepo */
	private $repo;
	/** @var UploadStash */
	public $stash;
	/** @var User */
	public $user;

	protected $mOffset;
	protected $mChunkIndex;
	protected $mFileKey;
	protected $mVirtualTempPath;

	/** @noinspection PhpMissingParentConstructorInspection */

	/**
	 * Setup local pointers to stash, repo and user (similar to UploadFromStash)
	 *
	 * @param User $user
	 * @param UploadStash|false $stash Default: false
	 * @param FileRepo|false $repo Default: false
	 */
	public function __construct( User $user, $stash = false, $repo = false ) {
		$this->user = $user;

		if ( $repo ) {
			$this->repo = $repo;
		} else {
			$this->repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		}

		if ( $stash ) {
			$this->stash = $stash;
		} else {
			wfDebug( __METHOD__ . " creating new UploadFromChunks instance for " . $user->getId() );
			$this->stash = new UploadStash( $this->repo, $this->user );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function tryStashFile( User $user, $isPartial = false ) {
		try {
			$this->verifyChunk();
		} catch ( UploadChunkVerificationException $e ) {
			return Status::newFatal( $e->msg );
		}

		return parent::tryStashFile( $user, $isPartial );
	}

	/**
	 * Calls the parent doStashFile and updates the uploadsession table to handle "chunks"
	 *
	 * @param User|null $user
	 * @return UploadStashFile Stashed file
	 */
	protected function doStashFile( User $user = null ) {
		// Stash file is the called on creating a new chunk session:
		$this->mChunkIndex = 0;
		$this->mOffset = 0;

		// Create a local stash target
		$this->mStashFile = parent::doStashFile( $user );
		// Update the initial file offset (based on file size)
		$this->mOffset = $this->mStashFile->getSize();
		$this->mFileKey = $this->mStashFile->getFileKey();

		// Output a copy of this first to chunk 0 location:
		$this->outputChunk( $this->mStashFile->getPath() );

		// Update db table to reflect initial "chunk" state
		$this->updateChunkStatus();

		return $this->mStashFile;
	}

	/**
	 * Continue chunk uploading
	 *
	 * @param string $name
	 * @param string $key
	 * @param WebRequestUpload $webRequestUpload
	 */
	public function continueChunks( $name, $key, $webRequestUpload ) {
		$this->mFileKey = $key;
		$this->mUpload = $webRequestUpload;
		// Get the chunk status form the db:
		$this->getChunkStatus();

		$metadata = $this->stash->getMetadata( $key );
		$this->initializePathInfo( $name,
			$this->getRealPath( $metadata['us_path'] ),
			$metadata['us_size'],
			false
		);
	}

	/**
	 * Append the final chunk and ready file for parent::performUpload()
	 * @return Status
	 */
	public function concatenateChunks() {
		$chunkIndex = $this->getChunkIndex();
		wfDebug( __METHOD__ . " concatenate {$this->mChunkIndex} chunks:" .
			$this->getOffset() . ' inx:' . $chunkIndex );

		// Concatenate all the chunks to mVirtualTempPath
		$fileList = [];
		// The first chunk is stored at the mVirtualTempPath path so we start on "chunk 1"
		for ( $i = 0; $i <= $chunkIndex; $i++ ) {
			$fileList[] = $this->getVirtualChunkLocation( $i );
		}

		// Get the file extension from the last chunk
		$ext = FileBackend::extensionFromPath( $this->mVirtualTempPath );
		// Get a 0-byte temp file to perform the concatenation at
		$tmpFile = MediaWikiServices::getInstance()->getTempFSFileFactory()
			->newTempFSFile( 'chunkedupload_', $ext );
		$tmpPath = false; // fail in concatenate()
		if ( $tmpFile ) {
			// keep alive with $this
			$tmpPath = $tmpFile->bind( $this )->getPath();
		}

		// Concatenate the chunks at the temp file
		$tStart = microtime( true );
		$status = $this->repo->concatenate( $fileList, $tmpPath, FileRepo::DELETE_SOURCE );
		$tAmount = microtime( true ) - $tStart;
		if ( !$status->isOK() ) {
			// This is a backend error and not user-related, so log is safe
			// Upload verification further on is not safe to log server side
			$this->logFileBackendStatus(
				$status,
				'[{type}] Error on concatenate {chunks} stashed files ({details})',
				[ 'chunks' => $chunkIndex ]
			);
			return $status;
		}

		wfDebugLog( 'fileconcatenate', "Combined $i chunks in $tAmount seconds." );

		// File system path of the actual full temp file
		$this->setTempFile( $tmpPath );

		$ret = $this->verifyUpload();
		if ( $ret['status'] !== UploadBase::OK ) {
			wfDebugLog( 'fileconcatenate', "Verification failed for chunked upload" );
			$status->fatal( $this->getVerificationErrorCode( $ret['status'] ) );

			return $status;
		}

		// Update the mTempPath and mStashFile
		// (for FileUpload or normal Stash to take over)
		$tStart = microtime( true );
		// This is a re-implementation of UploadBase::tryStashFile(), we can't call it because we
		// override doStashFile() with completely different functionality in this class...
		$error = $this->runUploadStashFileHook( $this->user );
		if ( $error ) {
			$status->fatal( ...$error );
			return $status;
		}
		try {
			$this->mStashFile = parent::doStashFile( $this->user );
		} catch ( UploadStashException $e ) {
			$status->fatal( 'uploadstash-exception', get_class( $e ), $e->getMessage() );
			return $status;
		}

		$tAmount = microtime( true ) - $tStart;
		// @phan-suppress-next-line PhanTypeMismatchArgumentNullable tmpFile is set when tmpPath is set here
		$this->mStashFile->setLocalReference( $tmpFile ); // reuse (e.g. for getImageInfo())
		wfDebugLog( 'fileconcatenate', "Stashed combined file ($i chunks) in $tAmount seconds." );

		return $status;
	}

	/**
	 * Returns the virtual chunk location:
	 * @param int $index
	 * @return string
	 */
	private function getVirtualChunkLocation( $index ) {
		return $this->repo->getVirtualUrl( 'temp' ) .
			'/' .
			$this->repo->getHashPath(
				$this->getChunkFileKey( $index )
			) .
			$this->getChunkFileKey( $index );
	}

	/**
	 * Add a chunk to the temporary directory
	 *
	 * @param string $chunkPath Path to temporary chunk file
	 * @param int $chunkSize Size of the current chunk
	 * @param int $offset Offset of current chunk ( mutch match database chunk offset )
	 * @return Status
	 */
	public function addChunk( $chunkPath, $chunkSize, $offset ) {
		// Get the offset before we add the chunk to the file system
		$preAppendOffset = $this->getOffset();

		if ( $preAppendOffset + $chunkSize > $this->getMaxUploadSize() ) {
			$status = Status::newFatal( 'file-too-large' );
		} else {
			// Make sure the client is uploading the correct chunk with a matching offset.
			if ( $preAppendOffset == $offset ) {
				// Update local chunk index for the current chunk
				$this->mChunkIndex++;
				try {
					# For some reason mTempPath is set to first part
					$oldTemp = $this->mTempPath;
					$this->mTempPath = $chunkPath;
					$this->verifyChunk();
					$this->mTempPath = $oldTemp;
				} catch ( UploadChunkVerificationException $e ) {
					return Status::newFatal( $e->msg );
				}
				$status = $this->outputChunk( $chunkPath );
				if ( $status->isGood() ) {
					// Update local offset:
					$this->mOffset = $preAppendOffset + $chunkSize;
					// Update chunk table status db
					$this->updateChunkStatus();
				}
			} else {
				$status = Status::newFatal( 'invalid-chunk-offset' );
			}
		}

		return $status;
	}

	/**
	 * Update the chunk db table with the current status:
	 */
	private function updateChunkStatus() {
		wfDebug( __METHOD__ . " update chunk status for {$this->mFileKey} offset:" .
			$this->getOffset() . ' inx:' . $this->getChunkIndex() );

		$dbw = $this->repo->getPrimaryDB();
		$dbw->newUpdateQueryBuilder()
			->update( 'uploadstash' )
			->set( [
				'us_status' => 'chunks',
				'us_chunk_inx' => $this->getChunkIndex(),
				'us_size' => $this->getOffset()
			] )
			->where( [ 'us_key' => $this->mFileKey ] )
			->caller( __METHOD__ )->execute();
	}

	/**
	 * Get the chunk db state and populate update relevant local values
	 */
	private function getChunkStatus() {
		// get primary db to avoid race conditions.
		// Otherwise, if chunk upload time < replag there will be spurious errors
		$dbw = $this->repo->getPrimaryDB();
		$row = $dbw->newSelectQueryBuilder()
			->select( [ 'us_chunk_inx', 'us_size', 'us_path' ] )
			->from( 'uploadstash' )
			->where( [ 'us_key' => $this->mFileKey ] )
			->caller( __METHOD__ )->fetchRow();
		// Handle result:
		if ( $row ) {
			$this->mChunkIndex = $row->us_chunk_inx;
			$this->mOffset = $row->us_size;
			$this->mVirtualTempPath = $row->us_path;
		}
	}

	/**
	 * Get the current Chunk index
	 * @return int Index of the current chunk
	 */
	private function getChunkIndex() {
		if ( $this->mChunkIndex !== null ) {
			return $this->mChunkIndex;
		}

		return 0;
	}

	/**
	 * Get the offset at which the next uploaded chunk will be appended to
	 * @return int Current byte offset of the chunk file set
	 */
	public function getOffset() {
		if ( $this->mOffset !== null ) {
			return $this->mOffset;
		}

		return 0;
	}

	/**
	 * Output the chunk to disk
	 *
	 * @param string $chunkPath
	 * @throws UploadChunkFileException
	 * @return Status
	 */
	private function outputChunk( $chunkPath ) {
		// Key is fileKey + chunk index
		$fileKey = $this->getChunkFileKey();

		// Store the chunk per its indexed fileKey:
		$hashPath = $this->repo->getHashPath( $fileKey );
		$storeStatus = $this->repo->quickImport( $chunkPath,
			$this->repo->getZonePath( 'temp' ) . "/{$hashPath}{$fileKey}" );

		// Check for error in stashing the chunk:
		if ( !$storeStatus->isOK() ) {
			$error = $this->logFileBackendStatus(
				$storeStatus,
				'[{type}] Error storing chunk in "{chunkPath}" for {fileKey} ({details})',
				[ 'chunkPath' => $chunkPath, 'fileKey' => $fileKey ]
			);
			throw new UploadChunkFileException( "Error storing file in '{chunkPath}': " .
				implode( '; ', $error ), [ 'chunkPath' => $chunkPath ] );
		}

		return $storeStatus;
	}

	private function getChunkFileKey( $index = null ) {
		return $this->mFileKey . '.' . ( $index ?? $this->getChunkIndex() );
	}

	/**
	 * Verify that the chunk isn't really an evil html file
	 *
	 * @throws UploadChunkVerificationException
	 */
	private function verifyChunk() {
		// Rest mDesiredDestName here so we verify the name as if it were mFileKey
		$oldDesiredDestName = $this->mDesiredDestName;
		$this->mDesiredDestName = $this->mFileKey;
		$this->mTitle = false;
		$res = $this->verifyPartialFile();
		$this->mDesiredDestName = $oldDesiredDestName;
		$this->mTitle = false;
		if ( is_array( $res ) ) {
			throw new UploadChunkVerificationException( $res );
		}
	}

	/**
	 * Log a status object from FileBackend functions (via FileRepo functions) to the upload log channel.
	 * Return a array with the first error to build up a exception message
	 *
	 * @param Status $status
	 * @param string $logMessage
	 * @param array $context
	 * @return array
	 */
	private function logFileBackendStatus( Status $status, string $logMessage, array $context = [] ): array {
		$logger = LoggerFactory::getInstance( 'upload' );
		$errorToThrow = null;
		$warningToThrow = null;

		foreach ( $status->getErrors() as $errorItem ) {
			// The message key stands for distinct error situation from the file backend,
			// each error situation should be shown up in aggregated stats as own point, replace in message
			$logMessageType = str_replace( '{type}', $errorItem['message'], $logMessage );

			// The message arguments often contains the name of the failing datacenter or file names
			// and should not show up in aggregated stats, add to context
			$context['details'] = implode( '; ', $errorItem['params'] );

			if ( $errorItem['type'] === 'error' ) {
				// Use the first error of the list for the exception text
				$errorToThrow ??= [ $errorItem['message'], ...$errorItem['params'] ];
				$logger->error( $logMessageType, $context );
			} else {
				// When no error is found, fall back to the first warning
				$warningToThrow ??= [ $errorItem['message'], ...$errorItem['params'] ];
				$logger->warning( $logMessageType, $context );
			}
		}
		return $errorToThrow ?? $warningToThrow ?? [ 'unknown', 'no error recorded' ];
	}
}
