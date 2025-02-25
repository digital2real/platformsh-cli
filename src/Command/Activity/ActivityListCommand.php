<?php
namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityListCommand extends ActivityCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('activity:list')
            ->setAliases(['activities', 'act']);

        // Add the --type option, with a link to help if configured.
        $typeDescription = 'Filter activities by type';
        if ($this->config()->has('service.activity_type_list_url')) {
            $typeDescription .= "\nFor a list of types see: <info>" . $this->config()->get('service.activity_type_list_url') . '</info>';
        }
        $this->addOption('type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, $typeDescription . "\n" . ArrayArgument::SPLIT_HELP);
        $this->addOption('exclude-type', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude activities by type.' . "\n" . ArrayArgument::SPLIT_HELP);

        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of results displayed', 10)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only activities created before this date will be listed')
            ->addOption('state', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter activities by state: in_progress, pending, complete, or cancelled.' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'Filter activities by result: success or failure')
            ->addOption('incomplete', 'i', InputOption::VALUE_NONE, 'Only list incomplete activities')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'List activities on all environments')
            ->setDescription('Get a list of activities for an environment or project');
        Table::configureInput($this->getDefinition());
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('List recent activities for the current environment')
             ->addExample('List all recent activities for the current project', '--all')
             ->addExample('List recent pushes', '--type environment.push')
             ->addExample('List pushes made before 15 March', '--type environment.push --start 2015-03-15')
             ->addExample('List up to 25 incomplete activities', '--limit 25 -i');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, $input->getOption('all'));

        $project = $this->getSelectedProject();

        if ($this->hasSelectedEnvironment() && !$input->getOption('all')) {
            $environmentSpecific = true;
            $apiResource = $this->getSelectedEnvironment();
        } else {
            $environmentSpecific = false;
            $apiResource = $project;
        }

        /** @var \Platformsh\Cli\Service\ActivityLoader $loader */
        $loader = $this->getService('activity_loader');
        $activities = $loader->loadFromInput($apiResource, $input);
        if ($activities === []) {
            $this->stdErr->writeln('No activities found');

            return 1;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $headers = ['ID', 'Created', 'Completed', 'Description', 'Type', 'Progress', 'State', 'Result', 'Environment(s)'];
        $defaultColumns = ['ID', 'Created', 'Description', 'Progress', 'State', 'Result'];

        if (!$environmentSpecific) {
            $defaultColumns[] = 'Environment(s)';
        }

        $rows = [];
        foreach ($activities as $activity) {
            $rows[] = [
                new AdaptiveTableCell($activity->id, ['wrap' => false]),
                $formatter->format($activity['created_at'], 'created_at'),
                $formatter->format($activity['completed_at'], 'completed_at'),
                ActivityMonitor::getFormattedDescription($activity, !$table->formatIsMachineReadable()),
                new AdaptiveTableCell($activity->type, ['wrap' => false]),
                $activity->getCompletionPercent() . '%',
                ActivityMonitor::formatState($activity->state),
                ActivityMonitor::formatResult($activity->result, !$table->formatIsMachineReadable()),
                implode(', ', $activity->environments)
            ];
        }

        if (!$table->formatIsMachineReadable()) {
            if ($environmentSpecific) {
                $this->stdErr->writeln(sprintf(
                    'Activities on the project %s, environment %s:',
                    $this->api()->getProjectLabel($project),
                    $this->api()->getEnvironmentLabel($apiResource)
                ));
            } else {
                $this->stdErr->writeln(sprintf(
                    'Activities on the project %s:',
                    $this->api()->getProjectLabel($project)
                ));
            }
        }

        $table->render($rows, $headers, $defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $executable = $this->config()->get('application.executable');

            $max = $input->getOption('limit') ? (int) $input->getOption('limit') : 10;
            $maybeMoreAvailable = count($activities) === $max;
            if ($maybeMoreAvailable) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf(
                    'More activities may be available.'
                    . ' To display older activities, increase <info>--limit</info> above %d, or set <info>--start</info> to a date in the past.'
                    . ' For more information, run: <info>%s activity:list -h</info>',
                    $max,
                    $executable
                ));
            }

            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view the log for an activity, run: <info>%s activity:log [id]</info>',
                $executable
            ));
            $this->stdErr->writeln(sprintf(
                'To view more information about an activity, run: <info>%s activity:get [id]</info>',
                $executable
            ));
        }

        return 0;
    }
}
