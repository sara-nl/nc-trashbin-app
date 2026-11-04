<?php

declare(strict_types=1);

// Psalm stubs for the parts of the Nextcloud server outside nextcloud/ocp that this app uses:
// the internal \OC\Files\View and the events of the files_trashbin app. The signatures follow
// Nextcloud 36; add a method here when lib/ starts using it.

namespace OC\Files {

	use OCP\Files\FileInfo;
	use OCP\Files\Storage\IStorage;

	class View {
		public function __construct(string $root = '') {
		}

		/** @return array{?IStorage, string} the storage and the internal path */
		public function resolvePath(string $path): array {
		}

		/**
		 * @param string $path
		 * @return bool
		 */
		public function mkdir($path) {
		}

		/**
		 * @param string $path
		 * @return bool
		 */
		public function rmdir($path) {
		}

		/**
		 * @param string $path
		 * @return bool
		 */
		public function is_dir($path) {
		}

		/**
		 * @param string $path
		 * @return bool
		 */
		public function file_exists($path) {
		}

		/**
		 * @param string $path
		 * @return bool
		 */
		public function unlink($path) {
		}

		/** @return FileInfo[] */
		public function getDirectoryContent(string $directory, ?string $mimeTypeFilter = null, ?FileInfo $directoryInfo = null) {
		}
	}
}

namespace OCA\Files_Trashbin\Events {

	use OCP\Files\Events\Node\AbstractNodesEvent;
	use OCP\Files\Node;

	class NodeRestoredEvent extends AbstractNodesEvent {
		public function __construct(Node $source, Node $target) {
		}
	}
}
