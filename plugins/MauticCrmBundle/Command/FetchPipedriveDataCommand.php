<?php

declare(strict_types=1);

namespace MauticPlugin\MauticCrmBundle\Command;

use Mautic\CoreBundle\Templating\Helper\TranslatorHelper;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticCrmBundle\Api\PipedriveApi;
use MauticPlugin\MauticCrmBundle\Integration\PipedriveIntegration;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class FetchPipedriveDataCommand extends ContainerAwareCommand
{
    private SymfonyStyle $io;
    private IntegrationHelper $integrationHelper;
    private TranslatorHelper $translatorHelper;

    public function __construct(
        IntegrationHelper $integrationHelper,
        TranslatorHelper $translatorHelper
    ) {
        $this->integrationHelper = $integrationHelper;
        $this->translatorHelper  = $translatorHelper;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('mautic:integration:pipedrive:fetch')
            ->setDescription('Pulls the data from Pipedrive and sends it to Mautic')
            ->addOption(
                '--restart',
                null,
                InputOption::VALUE_NONE,
                'Restart intgeration'
            );

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();
        $this->io  = new SymfonyStyle($input, $output);
        $this->io->warning(PipedriveIntegration::DEPRECATION_MESSAGE);

        /** @var PipedriveIntegration $integrationObject */
        $integrationObject = $this->integrationHelper
            ->getIntegrationObject(PipedriveIntegration::INTEGRATION_NAME);

        if (!$integrationObject || !$integrationObject->getIntegrationSettings()->getIsPublished()) {
            $this->io->note('Pipedrive integration is disabled.');

            return;
        }

        $types = [
            'owner' => PipedriveApi::USERS_API_ENDPOINT,
            'lead'  => PipedriveApi::PERSONS_API_ENDPOINT,
        ];

        if ($integrationObject->isCompanySupportEnabled()) {
            $types = ['company' => PipedriveApi::ORGANIZATIONS_API_ENDPOINT] + $types;
        }

        if ($input->getOption('restart')) {
            $this->io->note(
                $this->translatorHelper->trans(
                    'mautic.plugin.config.integration.restarted',
                    ['%integration%' => $integrationObject->getName()]
                )
            );
            $integrationObject->removeIntegrationEntities();
        }

        foreach ($types as $type => $endPoint) {
            $this->getData($type, $endPoint, $integrationObject);
        }

        $this->io->success('Execution time: '.number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3));
    }

    /**
     * @param                      $type
     * @param                      $endPoint
     * @param PipedriveIntegration $integrationObject
     */
    private function getData($type, $endPoint, $integrationObject)
    {
        $container  = $this->getContainer();
        $translator = $container->get('templating.helper.translator');

        $this->io->title('Pulling '.$type);
        $start = 0;
        $limit = 500;

        while (true) {
            $query = [
                'start' => $start,
                'limit' => $limit,
            ];
            $service = $container->get('mautic_integration.pipedrive.import.'.$type);
            $service->setIntegration($integrationObject);

            try {
                $result = $service->getData($query, $endPoint);
            } catch (\Exception $e) {
                return;
            }

            $this->io->text('Pulled '.$result['processed']);
            $this->io->note('Using '.memory_get_peak_usage(true) / 1000000 .' megabytes of ram.');

            if (!$result['more_items_in_collection']) {
                return;
            }

            $start += $limit;
            $this->io->text('Pulling more...');
        }
    }
}
