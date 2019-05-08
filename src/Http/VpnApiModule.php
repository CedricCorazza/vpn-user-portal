<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Portal\Http;

use DateInterval;
use DateTime;
use DateTimeZone;
use LC\Portal\CA\CaInterface;
use LC\Portal\ClientConfig;
use LC\Portal\Config\ProfileConfig;
use LC\Portal\Http\Exception\InputValidationException;
use LC\Portal\OAuth\VpnAccessTokenInfo;
use LC\Portal\Random;
use LC\Portal\Storage;
use LC\Portal\TlsCrypt;

class VpnApiModule implements ServiceModuleInterface
{
    /** @var \LC\Portal\Storage */
    private $storage;

    /** @var array<string,\LC\Portal\Config\ProfileConfig> */
    private $profileConfigList;

    /** @var \LC\Portal\CA\CaInterface */
    private $ca;

    /** @var \LC\Portal\TlsCrypt */
    private $tlsCrypt;

    /** @var \DateInterval */
    private $sessionExpiry;

    /** @var bool */
    private $shuffleHosts = true;

    /** @var \DateTime */
    private $dateTime;

    /** @var \LC\Portal\RandomInterface */
    private $random;

    /**
     * @param Storage                                       $storage
     * @param array<string,\LC\Portal\Config\ProfileConfig> $profileConfigList
     * @param \DateInterval                                 $sessionExpiry
     */
    public function __construct(Storage $storage, array $profileConfigList, CaInterface $ca, TlsCrypt $tlsCrypt, DateInterval $sessionExpiry)
    {
        $this->storage = $storage;
        $this->profileConfigList = $profileConfigList;
        $this->ca = $ca;
        $this->tlsCrypt = $tlsCrypt;
        $this->sessionExpiry = $sessionExpiry;
        $this->dateTime = new DateTime();
        $this->random = new Random();
    }

    /**
     * @param bool $shuffleHosts
     *
     * @return void
     */
    public function setShuffleHosts($shuffleHosts)
    {
        $this->shuffleHosts = (bool) $shuffleHosts;
    }

    /**
     * @return void
     */
    public function init(Service $service)
    {
        // API 1, 2
        $service->get(
            '/profile_list',
            /**
             * @return ApiResponse
             */
            function (Request $request, array $hookData) {
                /** @var \LC\Portal\OAuth\VpnAccessTokenInfo */
                $accessTokenInfo = $hookData['auth'];
                $userPermissions = $this->getPermissionList($accessTokenInfo);
                $userProfileList = [];
                foreach ($this->profileConfigList as $profileId => $profileConfig) {
                    if ($profileConfig->getHideProfile()) {
                        continue;
                    }
                    if ($profileConfig->getEnableAcl()) {
                        // is the user member of the aclPermissionList?
                        if (!VpnPortalModule::isMember($profileConfig->getAclPermissionList(), $userPermissions)) {
                            continue;
                        }
                    }

                    $userProfileList[] = [
                        'profile_id' => $profileId,
                        'display_name' => $profileConfig->getDisplayName(),
                        // 2FA is now decided by vpn-user-portal setting, so
                        // we "lie" here to the client
                        'two_factor' => false,
                    ];
                }

                return new ApiResponse('profile_list', $userProfileList);
            }
        );

        // API 2
        // DEPRECATED, this whole call is useless now!
        $service->get(
            '/user_info',
            /**
             * @return ApiResponse
             */
            function (Request $request, array $hookData) {
                /** @var \LC\Portal\OAuth\VpnAccessTokenInfo */
                $accessTokenInfo = $hookData['auth'];

                return new ApiResponse(
                    'user_info',
                    [
                        'user_id' => $accessTokenInfo->getUserId(),
                        // as 2FA works through the portal now, lie here to the
                        // clients so they won't try to enroll the user
                        'two_factor_enrolled' => false,
                        'two_factor_enrolled_with' => [],
                        'two_factor_supported_methods' => [],
                        // if the user is disabled, the access_token no longer
                        // works, so this is bogus
                        'is_disabled' => false,
                    ]
                );
            }
        );

        // API 2
        $service->post(
            '/create_keypair',
            /**
             * @return \LC\Portal\Http\Response
             */
            function (Request $request, array $hookData) {
                /** @var \LC\Portal\OAuth\VpnAccessTokenInfo */
                $accessTokenInfo = $hookData['auth'];
                try {
                    $clientCertificate = $this->getCertificate($accessTokenInfo);

                    return new ApiResponse(
                        'create_keypair',
                        [
                            'certificate' => $clientCertificate['certificate'],
                            'private_key' => $clientCertificate['private_key'],
                        ]
                    );
                } catch (InputValidationException $e) {
                    return new ApiErrorResponse('create_keypair', $e->getMessage());
                }
            }
        );

        // API 2
        $service->get(
            '/check_certificate',
            /**
             * @return \LC\Portal\Http\Response
             */
            function (Request $request, array $hookData) {
                /** @var \LC\Portal\OAuth\VpnAccessTokenInfo */
                $accessTokenInfo = $hookData['auth'];
                $commonName = InputValidation::commonName($request->getQueryParameter('common_name'));
                $clientCertificateInfo = $this->storage->getUserCertificateInfo($commonName);
                $responseData = $this->validateCertificate($clientCertificateInfo);

                return new ApiResponse(
                    'check_certificate',
                    $responseData
                );
            }
        );

        // API 2
        $service->get(
            '/profile_config',
            /**
             * @return \LC\Portal\Http\Response
             */
            function (Request $request, array $hookData) {
                /** @var \LC\Portal\OAuth\VpnAccessTokenInfo */
                $accessTokenInfo = $hookData['auth'];
                try {
                    $requestedProfileId = InputValidation::profileId($request->getQueryParameter('profile_id'));
                    $userPermissions = $this->getPermissionList($accessTokenInfo);

                    $availableProfiles = [];
                    foreach ($this->profileConfigList as $profileId => $profileConfig) {
                        if ($profileConfig->getHideProfile()) {
                            continue;
                        }
                        if ($profileConfig->getEnableAcl()) {
                            // is the user member of the userPermissions?
                            if (!VpnPortalModule::isMember($profileConfig->getAclPermissionList(), $userPermissions)) {
                                continue;
                            }
                        }

                        $availableProfiles[] = $profileId;
                    }

                    if (!\in_array($requestedProfileId, $availableProfiles, true)) {
                        return new ApiErrorResponse('profile_config', 'user has no access to this profile');
                    }

                    return $this->getConfigOnly($this->profileConfigList[$requestedProfileId]);
                } catch (InputValidationException $e) {
                    return new ApiErrorResponse('profile_config', $e->getMessage());
                }
            }
        );

        // API 1, 2
        $service->get(
            '/user_messages',
            /**
             * @return ApiResponse
             */
            function (Request $request, array $hookData) {
                return new ApiResponse(
                    'user_messages',
                    []
                );
            }
        );

        // API 1, 2
        $service->get(
            '/system_messages',
            /**
             * @return ApiResponse
             */
            function (Request $request, array $hookData) {
                $msgList = [];
                $motdMessages = $this->storage->systemMessages('motd');
                foreach ($motdMessages as $motdMessage) {
                    $dateTime = new DateTime($motdMessage['date_time']);
                    $dateTime->setTimezone(new DateTimeZone('UTC'));

                    $msgList[] = [
                        // no support yet for 'motd' type in application API
                        'type' => 'notification',
                        'date_time' => $dateTime->format('Y-m-d\TH:i:s\Z'),
                        'message' => $motdMessage['message'],
                    ];
                }

                return new ApiResponse(
                    'system_messages',
                    $msgList
                );
            }
        );
    }

    /**
     * @param ProfileConfig $profileId
     *
     * @return Response
     */
    private function getConfigOnly(ProfileConfig $profileConfig)
    {
        // obtain information about this profile to be able to construct
        // a client configuration file
        $serverInfo = [
            'tls_crypt' => $this->tlsCrypt->get(),
            'ca' => $this->ca->caCert(),
        ];

        $clientConfig = ClientConfig::get($profileConfig, $serverInfo, [], $this->shuffleHosts);
        $clientConfig = str_replace("\n", "\r\n", $clientConfig);

        $response = new Response(200, 'application/x-openvpn-profile');
        $response->setBody($clientConfig);

        return $response;
    }

    /**
     * @param \LC\Portal\OAuth\VpnAccessTokenInfo $accessTokenInfo
     *
     * @return array
     */
    private function getCertificate(VpnAccessTokenInfo $accessTokenInfo)
    {
        // generate a random string as the certificate's CN
        $commonName = $this->random->get(16);
        $certInfo = $this->ca->clientCert($commonName, $this->getExpiresAt($accessTokenInfo));

        $this->storage->addCertificate(
            $accessTokenInfo->getUserId(),
            $commonName,
            $accessTokenInfo->getClientId(),
            new DateTime(sprintf('@%d', $certInfo['valid_from'])),
            new DateTime(sprintf('@%d', $certInfo['valid_to'])),
            $accessTokenInfo->getClientId()
        );

        $this->storage->addUserMessage(
            $accessTokenInfo->getUserId(),
            'notification',
            sprintf('new certificate generated by application "%s"', $accessTokenInfo->getClientId())
        );

        return $certInfo;
    }

    /**
     * @param false|array $clientCertificateInfo
     *
     * @return array<string, bool|string>
     */
    private function validateCertificate($clientCertificateInfo)
    {
        $reason = '';
        if (false === $clientCertificateInfo) {
            // certificate with this CN does not exist, was deleted by
            // user, or complete new installation of service with new
            // CA
            $isValid = false;
            $reason = 'certificate_missing';
        } elseif (new DateTime($clientCertificateInfo['valid_from']) > $this->dateTime) {
            // certificate not yet valid
            $isValid = false;
            $reason = 'certificate_not_yet_valid';
        } elseif (new DateTime($clientCertificateInfo['valid_to']) < $this->dateTime) {
            // certificate not valid anymore
            $isValid = false;
            $reason = 'certificate_expired';
        } else {
            $isValid = true;
        }

        $responseData = [
            'is_valid' => $isValid,
        ];

        if (!$isValid) {
            $responseData['reason'] = $reason;
        }

        return $responseData;
    }

    /**
     * @param \LC\Portal\OAuth\VpnAccessTokenInfo $accessTokenInfo
     *
     * @return array<string>
     */
    private function getPermissionList(VpnAccessTokenInfo $accessTokenInfo)
    {
        if (!$accessTokenInfo->getIsLocal()) {
            return [];
        }

        return $this->storage->getPermissionList($accessTokenInfo->getUserId());
    }

    /**
     * @param \LC\Portal\OAuth\VpnAccessTokenInfo $accessTokenInfo
     *
     * @return \DateTime
     */
    private function getExpiresAt(VpnAccessTokenInfo $accessTokenInfo)
    {
        if (!$accessTokenInfo->getIsLocal()) {
            return date_add(clone $this->dateTime, $this->sessionExpiry);
        }

        return new DateTime($this->storage->getSessionExpiresAt($accessTokenInfo->getUserId()));
    }
}
