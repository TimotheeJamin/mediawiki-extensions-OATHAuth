<?php

namespace MediaWiki\Extension\OATHAuth\Key;

/**
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
 */

use Base32\Base32;
use jakobo\HOTP\HOTP;
use MediaWiki\Extension\OATHAuth\OATHUser;
use Psr\Log\LoggerInterface;
use MediaWiki\Logger\LoggerFactory;
use DomainException;
use Exception;
use MWException;
use ObjectCache;
use CentralIdLookup;
use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\OATHAuth\IAuthKey;

/**
 * Class representing a two-factor key
 *
 * Keys can be tied to OATHUsers
 *
 * @ingroup Extensions
 */
class TOTPKey implements IAuthKey {
	/**
	 * Represents that a token corresponds to the main secret
	 * @see verify
	 */
	const MAIN_TOKEN = 1;

	/**
	 * Represents that a token corresponds to a scratch token
	 * @see verify
	 */
	const SCRATCH_TOKEN = -1;

	/** @var array Two factor binary secret */
	private $secret;

	/** @var string[] List of scratch tokens */
	private $scratchTokens;

	/**
	 * @return TOTPKey
	 * @throws Exception
	 */
	public static function newFromRandom() {
		$object = new self(
			Base32::encode( random_bytes( 10 ) ),
			[]
		);

		$object->regenerateScratchTokens();

		return $object;
	}

	/**
	 * @param string $secret
	 * @param array $scratchTokens
	 */
	public function __construct( $secret, array $scratchTokens ) {
		// Currently hardcoded values; might be used in future
		$this->secret = [
			'mode' => 'hotp',
			'secret' => $secret,
			'period' => 30,
			'algorithm' => 'SHA1',
		];
		$this->scratchTokens = $scratchTokens;
	}

	/**
	 * @return string
	 */
	public function getSecret() {
		return $this->secret['secret'];
	}

	/**
	 * @return array
	 */
	public function getScratchTokens() {
		return $this->scratchTokens;
	}

	/**
	 * @param array $data
	 * @param OATHUser $user
	 * @return bool|int
	 * @throws MWException
	 */
	public function verify( $data, OATHUser $user ) {
		global $wgOATHAuthWindowRadius;

		$token = $data['token'];

		if ( $this->secret['mode'] !== 'hotp' ) {
			throw new DomainException( 'OATHAuth extension does not support non-HOTP tokens' );
		}

		// Prevent replay attacks
		$memc = ObjectCache::newAnything( [] );
		$uid = CentralIdLookup::factory()->centralIdFromLocalUser( $user->getUser() );
		$memcKey = ObjectCache::getLocalClusterInstance()->makeKey( 'oathauth-totp', 'usedtokens', $uid );
		$lastWindow = (int)$memc->get( $memcKey );

		$retval = false;
		$results = HOTP::generateByTimeWindow(
			Base32::decode( $this->secret['secret'] ),
			$this->secret['period'], -$wgOATHAuthWindowRadius, $wgOATHAuthWindowRadius
		);

		// Remove any whitespace from the received token, which can be an intended group seperator
		// or trimmeable whitespace
		$token = preg_replace( '/\s+/', '', $token );

		$clientIP = $user->getUser()->getRequest()->getIP();

		$logger = $this->getLogger();

		// Check to see if the user's given token is in the list of tokens generated
		// for the time window.
		foreach ( $results as $window => $result ) {
			if ( $window > $lastWindow && $result->toHOTP( 6 ) === $token ) {
				$lastWindow = $window;
				$retval = self::MAIN_TOKEN;

				$logger->info( 'OATHAuth user {user} entered a valid OTP from {clientip}', [
					'user' => $user->getAccount(),
					'clientip' => $clientIP,
				] );
				break;
			}
		}

		// See if the user is using a scratch token
		if ( !$retval ) {
			$length = count( $this->scratchTokens );
			// Detect condition where all scratch tokens have been used
			if ( $length === 1 && $this->scratchTokens[0] === "" ) {
				$retval = false;
			} else {
				for ( $i = 0; $i < $length; $i++ ) {
					if ( $token === $this->scratchTokens[$i] ) {
						// If there is a scratch token, remove it from the scratch token list
						unset( $this->scratchTokens[$i] );

						$logger->info( 'OATHAuth user {user} used a scratch token from {clientip}', [
							'user' => $user->getAccount(),
							'clientip' => $clientIP,
						] );

						$auth = MediaWikiServices::getInstance()->getService( 'OATHAuth' );
						$module = $auth->getModuleByKey( 'totp' );
						$userRepo = MediaWikiServices::getInstance()->getService( 'OATHUserRepository' );
						$user->addKey( $this );
						$user->setModule( $module );
						$userRepo->persist( $user, $clientIP );
						// Only return true if we removed it from the database
						$retval = self::SCRATCH_TOKEN;
						break;
					}
				}
			}
		}

		if ( $retval ) {
			$memc->set(
				$memcKey,
				$lastWindow,
				$this->secret['period'] * ( 1 + 2 * $wgOATHAuthWindowRadius )
			);
		} else {
			$logger->info( 'OATHAuth user {user} failed OTP/scratch token from {clientip}', [
				'user' => $user->getAccount(),
				'clientip' => $clientIP,
			] );

			// Increase rate limit counter for failed request
			$user->getUser()->pingLimiter( 'badoath' );
		}

		return $retval;
	}

	public function regenerateScratchTokens() {
		$scratchTokens = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$scratchTokens[] = Base32::encode( random_bytes( 10 ) );
		}
		$this->scratchTokens = $scratchTokens;
	}

	/**
	 * Check if a token is one of the scratch tokens for this two factor key.
	 *
	 * @param string $token Token to verify
	 *
	 * @return bool true if this is a scratch token.
	 */
	public function isScratchToken( $token ) {
		$token = preg_replace( '/\s+/', '', $token );
		return in_array( $token, $this->scratchTokens, true );
	}

	/**
	 * @return LoggerInterface
	 */
	private function getLogger() {
		return LoggerFactory::getInstance( 'authentication' );
	}
}
