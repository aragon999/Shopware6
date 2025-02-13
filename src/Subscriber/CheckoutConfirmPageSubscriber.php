<?php

namespace Kiener\MolliePayments\Subscriber;

use Exception;
use Kiener\MolliePayments\Factory\MollieApiFactory;
use Kiener\MolliePayments\Service\CustomerService;
use Kiener\MolliePayments\Service\CustomFieldService;
use Kiener\MolliePayments\Service\Payment\Provider\ActivePaymentMethodsProvider;
use Kiener\MolliePayments\Service\PaymentMethodService;
use Kiener\MolliePayments\Service\SettingsService;
use Kiener\MolliePayments\Setting\MollieSettingStruct;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Method;
use Mollie\Api\Types\PaymentMethod;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

class CheckoutConfirmPageSubscriber implements EventSubscriberInterface
{

    /**
     * @var MollieApiFactory
     */
    private $apiFactory;

    /**
     * @var MollieApiClient
     */
    private $apiClient;

    /**
     * @var SettingsService
     */
    private $settingsService;

    /**
     * @var MollieSettingStruct
     */
    private $settings;

    /**
     * @var EntityRepositoryInterface
     */
    private $languageRepositoryInterface;

    /**
     * @var EntityRepositoryInterface
     */
    private $localeRepositoryInterface;


    /**
     * @return array<mixed>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => [
                ['addDataToPage', 10],
            ],
            AccountEditOrderPageLoadedEvent::class => ['addDataToPage', 10],
        ];
    }

    /**
     * @param MollieApiFactory $apiFactory
     * @param SettingsService $settingsService
     * @param EntityRepositoryInterface $languageRepositoryInterface
     * @param EntityRepositoryInterface $localeRepositoryInterface
     */
    public function __construct(MollieApiFactory $apiFactory, SettingsService $settingsService, EntityRepositoryInterface $languageRepositoryInterface, EntityRepositoryInterface $localeRepositoryInterface)
    {
        $this->apiFactory = $apiFactory;
        $this->settingsService = $settingsService;
        $this->languageRepositoryInterface = $languageRepositoryInterface;
        $this->localeRepositoryInterface = $localeRepositoryInterface;
    }


    /**
     * @param AccountEditOrderPageLoadedEvent|CheckoutConfirmPageLoadedEvent $args
     * @throws \Mollie\Api\Exceptions\IncompatiblePlatform
     */
    public function addDataToPage($args): void
    {
        # load our settings for the
        # current request
        $this->settings = $this->settingsService->getSettings($args->getSalesChannelContext()->getSalesChannel()->getId());

        # now use our factory to get the correct
        # client with the correct sales channel settings
        $this->apiClient = $this->apiFactory->getClient(
            $args->getSalesChannelContext()->getSalesChannel()->getId()
        );

        $this->addMollieLocaleVariableToPage($args);
        $this->addMollieProfileIdVariableToPage($args);
        $this->addMollieTestModeVariableToPage($args);
        $this->addMollieComponentsVariableToPage($args);
        $this->addMollieIdealIssuersVariableToPage($args);
    }

    /**
     * Adds the locale for Mollie components to the storefront.
     *
     * @param AccountEditOrderPageLoadedEvent|CheckoutConfirmPageLoadedEvent $args
     */
    private function addMollieLocaleVariableToPage($args): void
    {
        /**
         * Build an array of available locales.
         */
        $availableLocales = [
            'en_US',
            'en_GB',
            'nl_NL',
            'fr_FR',
            'it_IT',
            'de_DE',
            'de_AT',
            'de_CH',
            'es_ES',
            'ca_ES',
            'nb_NO',
            'pt_PT',
            'sv_SE',
            'fi_FI',
            'da_DK',
            'is_IS',
            'hu_HU',
            'pl_PL',
            'lv_LV',
            'lt_LT'
        ];

        /**
         * Get the language object from the sales channel context.
         */
        $locale = '';

        $context = $args->getContext();
        $salesChannelContext = $args->getSalesChannelContext();


        $salesChannel = $salesChannelContext->getSalesChannel();
        if ($salesChannel !== null) {
            $languageId = $salesChannel->getLanguageId();
            if ($languageId !== null) {
                $languageCriteria = new Criteria();
                $languageCriteria->addFilter(new EqualsFilter('id', $languageId));

                $languages = $this->languageRepositoryInterface->search($languageCriteria, $args->getContext());
                $localeId = $languages->first()->getLocaleId();
                $localeCriteria = new Criteria();
                $localeCriteria->addFilter(new EqualsFilter('id', $localeId));

                $locales = $this->localeRepositoryInterface->search($localeCriteria, $args->getContext());
                $locale = $locales->first()->getCode();
            }
        }


        /**
         * Set the locale based on the current storefront.
         */


        if ($locale !== null && $locale !== '') {
            $locale = str_replace('-', '_', $locale);
        }

        /**
         * Check if the shop locale is available.
         */
        if ($locale === '' || !in_array($locale, $availableLocales, true)) {
            $locale = 'en_GB';
        }


        $args->getPage()->assign([
            'mollie_locale' => $locale,
        ]);
    }

    /**
     * Adds the test mode variable to the storefront.
     *
     * @param AccountEditOrderPageLoadedEvent|CheckoutConfirmPageLoadedEvent $args
     */
    private function addMollieTestModeVariableToPage($args): void
    {
        $args->getPage()->assign([
            'mollie_test_mode' => $this->settings->isTestMode() ? 'true' : 'false',
        ]);
    }

    /**
     * Adds the profile id to the storefront.
     *
     * @param AccountEditOrderPageLoadedEvent|CheckoutConfirmPageLoadedEvent $args
     */
    private function addMollieProfileIdVariableToPage($args): void
    {
        $mollieProfileId = '';

        /**
         * Fetches the profile id from Mollie's API for the current key.
         */
        try {
            if ($this->apiClient->usesOAuth() === false) {
                $mollieProfile = $this->apiClient->profiles->get('me');
            } else {
                $mollieProfile = $this->apiClient->profiles->page()->offsetGet(0);
            }

            if (isset($mollieProfile->id)) {
                $mollieProfileId = $mollieProfile->id;
            }
        } catch (ApiException $e) {
            //
        }

        $args->getPage()->assign([
            'mollie_profile_id' => $mollieProfileId,
        ]);
    }

    /**
     * Adds the components variable to the storefront.
     *
     * @param AccountEditOrderPageLoadedEvent|CheckoutConfirmPageLoadedEvent $args
     */
    private function addMollieComponentsVariableToPage($args): void
    {
        $args->getPage()->assign([
            'enable_credit_card_components' => $this->settings->getEnableCreditCardComponents(),
        ]);
    }

    /**
     * Adds ideal issuers variable to the storefront.
     *
     * @param AccountEditOrderPageLoadedEvent|CheckoutConfirmPageLoadedEvent $args
     */
    private function addMollieIdealIssuersVariableToPage($args): void
    {
        $customFields = [];
        $ideal = null;
        $mollieProfileId = '';
        $preferredIssuer = '';

        /**
         * Fetches the profile id from Mollie's API for the current key.
         */
        try {
            if ($this->apiClient->usesOAuth() === false) {
                $mollieProfile = $this->apiClient->profiles->get('me');
            } else {
                $mollieProfile = $this->apiClient->profiles->page()->offsetGet(0);
            }

            if (isset($mollieProfile->id)) {
                $mollieProfileId = $mollieProfile->id;
            }
        } catch (ApiException $e) {
            //
        }

        // Get custom fields from the customer in the sales channel context
        if ($args->getSalesChannelContext()->getCustomer() !== null) {
            $customFields = $args->getSalesChannelContext()->getCustomer()->getCustomFields();
        }

        // Get the preferred issuer from the custom fields
        if (
            is_array($customFields)
            && isset($customFields[CustomFieldService::CUSTOM_FIELDS_KEY_MOLLIE_PAYMENTS][CustomerService::CUSTOM_FIELDS_KEY_PREFERRED_IDEAL_ISSUER])
            && (string)$customFields[CustomFieldService::CUSTOM_FIELDS_KEY_MOLLIE_PAYMENTS][CustomerService::CUSTOM_FIELDS_KEY_PREFERRED_IDEAL_ISSUER] !== ''
        ) {
            $preferredIssuer = $customFields[CustomFieldService::CUSTOM_FIELDS_KEY_MOLLIE_PAYMENTS][CustomerService::CUSTOM_FIELDS_KEY_PREFERRED_IDEAL_ISSUER];
        }


        $parameters = [
            'include' => 'issuers',
        ];

        if ($this->apiClient->usesOAuth()) {
            $parameters['profileId'] = $mollieProfileId;
        }

        // Get issuers from the API
        try {
            $ideal = $this->apiClient->methods->get(PaymentMethod::IDEAL, $parameters);
        } catch (Exception $e) {
            //
        }

        // Assign issuers to storefront
        if ($ideal instanceof Method) {
            $args->getPage()->assign([
                'ideal_issuers' => $ideal->issuers,
                'preferred_issuer' => $preferredIssuer,
            ]);
        }
    }
}
