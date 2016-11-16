<?php
/**
 * Copyright © 2016 MageCode. All rights reserved.
 */

namespace MageCode\ConsoleCommands\Console\Command\Statistics;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Locale\ResolverInterface;

class Refresh extends Command
{
    const DATE_FROM_ARGUMENT = 'date-from';

    const DATE_TO_ARGUMENT = 'date-to';

    protected $timezone;

    protected $configLoader;

    protected $objectManager;

    protected $reportTypes;

    public function __construct(
        TimezoneInterface $timezone,
        ConfigLoaderInterface $configLoader,
        ObjectManagerInterface $objectManager,
        ResolverInterface $localeResolver,
        array $reportTypes = []
    )
    {
        $this->timezone = $timezone;
        $this->configLoader = $configLoader;
        $this->objectManager = $objectManager;
        $this->localeResolver = $localeResolver;
        $this->reportTypes = $reportTypes;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('magecode:statistics:refresh')
            ->setDescription('Refresh Statistics')
            ->setDefinition([
                new InputArgument(
                    self::DATE_FROM_ARGUMENT,
                    InputArgument::OPTIONAL,
                    'Date From'
                ),
                new InputArgument(
                    self::DATE_TO_ARGUMENT,
                    InputArgument::OPTIONAL,
                    'Date To'
                ),
            ]);
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!count($this->reportTypes)) {
            throw new \Exception('Argument "reportTypes" not found in "' . __CLASS__ . '"');
        }

        extract($this->_resolveArguments($input));

        $output->writeln('<info>Date From: ' . ($dateFrom ? $dateFrom->format('r') : '--') . '</info>');
        $output->writeln('<info>Date To: ' . ($dateTo ? $dateTo->format('r') : '--') . '</info>');

        if ($dateFrom) {
            $dateFrom->sub(new \DateInterval('PT25H'));
        }
        if ($dateTo) {
            $dateTo->sub(new \DateInterval('PT25H'));
        }

        $this->localeResolver->emulate(0);

        foreach ($this->reportTypes as $reportName => $reportType) {
            $output->writeln('<comment>Refresh "' . addslashes($reportName) . '"</comment>');
            $this->objectManager->create($reportType)->aggregate($dateFrom, $dateTo);
        }

        $this->localeResolver->revert();

        $output->writeln('<info>Refresh Done.</info>');
    }

    protected function _resolveArguments(InputInterface $input)
    {
        $dateFrom = null;
        if ($input->hasArgument(self::DATE_FROM_ARGUMENT) && $input->getArgument(self::DATE_FROM_ARGUMENT)) {
            try {
                $dateFrom = $this->timezone->date(new \DateTime($input->getArgument(self::DATE_FROM_ARGUMENT)));
            } catch (\Exception $e) {
                throw new \Exception('"Date From" formatted wrong. Use [d-m-Y]');
            }
        }

        $dateTo = null;
        if ($input->hasArgument(self::DATE_TO_ARGUMENT) && $input->getArgument(self::DATE_TO_ARGUMENT)) {
            try {
                $dateTo = $this->timezone->date($input->getArgument(self::DATE_TO_ARGUMENT));
            } catch (\Exception $e) {
                throw new \Exception('"Date To" formatted wrong. Use [d-m-Y]');
            }
        }

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo
        ];
    }
}
