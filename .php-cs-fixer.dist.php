<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once './vendor-bin/cs-fixer/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->notPath('l10n')
	->notPath('node_modules')
	->notPath('vendor')
	->notPath('vendor-bin')
	->in(__DIR__);

return $config;
