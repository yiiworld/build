<?php
/**
 * BuildCommand.php
 * @author Revin Roman http://phptime.ru
 */

namespace cookyii\build\commands;

use cookyii\build\events\TaskEvent;
use Symfony\Component\Console;

/**
 * Class BuildCommand
 * @package cookyii\build\commands
 */
class BuildCommand extends AbstractCommand
{

    /** @var \Symfony\Component\EventDispatcher\EventDispatcher */
    public $eventDispatcher;

    /** @var array */
    public $rawConfig = [];

    /** @var array */
    public $config = [];

    /** @var \cookyii\build\config\AbstractConfigReader */
    public $configReader;

    /** @var array */
    private $executed = [];

    /** Events */
    const EVENT_BEFORE_CREATE_TASK_OBJECT = 'build.onBeforeCreateTaskObject';
    const EVENT_AFTER_CREATE_TASK_OBJECT = 'build.onAfterCreateTaskObject';
    const EVENT_BEFORE_EXECUTE_TASK = 'build.onBeforeExecuteTask';
    const EVENT_AFTER_EXECUTE_TASK = 'build.onAfterExecuteTask';

    protected function configure()
    {
        parent::configure();

        $this
            ->setName('build')
            ->setDescription('Build current project')
            ->addArgument(
                'task',
                Console\Input\InputArgument::OPTIONAL,
                'What task you need to execute?',
                'default'
            )
            ->addOption('config', 'c', Console\Input\InputOption::VALUE_OPTIONAL, 'Where is the configuration file', 'build.php')
            ->addOption('config-type', 't', Console\Input\InputOption::VALUE_OPTIONAL, 'Config type (default, phing, json)', 'default')
            ->addOption('task-delimiter', null, Console\Input\InputOption::VALUE_OPTIONAL, 'Delimiter for the name of the task', '/')
            ->addOption('loop-threshold', null, Console\Input\InputOption::VALUE_OPTIONAL, 'Number of repetitions of the task to be discarded error loop', 3)
            ->addOption('disable-events', null, Console\Input\InputOption::VALUE_OPTIONAL, 'Disable event in this run', false)
            ->addOption('color', null, Console\Input\InputOption::VALUE_OPTIONAL, 'Support colors in output', 'yes');

        $this->eventDispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
    }

    /**
     * @param Console\Input\InputInterface $input
     * @param Console\Output\ConsoleOutput $output
     * @return integer
     */
    protected function execute(Console\Input\InputInterface $input, Console\Output\ConsoleOutput $output)
    {
        $result = 0;

        $started_at = microtime(true);

        $this->input = $input;
        $this->output = $output;

        $this->setStyles();

        $this->log('<task-result> CONF </task-result> Reading config... ', 0, false);

        $this->readConfig();
        $this->registerEventListeners();

        if (empty($this->config)) {
            $this->log('<error>Unable to read config file.</error>');

            if ($this->output->isVerbose()) {
                $this->log(sprintf('<comment>[config file]</comment> %s', $this->configReader->configFile), 1);
            }

            $result = 1;
        } else {
            $this->log('ok.');

            if ($this->output->isVerbose()) {
                $this->log(sprintf('<comment>[config file]</comment> %s', $this->configReader->configFile), 1);
            }

            $task_name = $input->getArgument('task');
            if (empty($task_name)) {
                throw new \InvalidArgumentException('Empty task name.');
            }

            $this->log('');

            $task = $this->findTask($task_name);

            if (!$this->executeTask($task, $task_name, 0)) {
                $this->log('<task-error> TASK </task-error> <error>[' . $task_name . '] failure.</error>');

                $result = 1;
            }
        }

        $this->footer($started_at);

        return $result;
    }

    /**
     * @param string $task_name
     * @return array
     */
    private function findTask($task_name)
    {
        $this->detectLoop($task_name);

        if (empty($task_name)) {
            throw new \InvalidArgumentException(sprintf('Bad task name "%s".', $task_name));
        } else {
            if (!isset($this->config[$task_name])) {
                throw new \RuntimeException(sprintf('%s task not found.', $task_name));
            }

            return $this->config[$task_name];
        }
    }

    /**
     * @param array $task
     * @param string $prefix
     * @param integer $indent
     * @return bool
     */
    public function executeTask(array $task, $prefix, $indent)
    {
        $this->log('<task> TASK </task> ' . $prefix);

        $className = isset($task['.task']) && !empty($task['.task'])
            ? is_array($task['.task']) ? $task['.task']['class'] : $task['.task']
            : 'cookyii\build\tasks\BlankTask';

        if (!class_exists($className)) {
            throw new \RuntimeException(sprintf('Class "%s" not found.', $className));
        }

        if ($this->output->isVerbose()) {
            $this->log('<comment>[class]</comment> ', $indent + 1, false);
            $this->log($className);
        }

        $Event = new TaskEvent($this, $task, $indent + 1);

        if (!$this->raiseEvent(static::EVENT_BEFORE_CREATE_TASK_OBJECT, $Event)) {
            return false;
        }

        /** @var \cookyii\build\tasks\AbstractTask $Task */
        $Task = new $className($this);

        $attributes = isset($task['.task']) && !empty($task['.task'])
            ? is_array($task['.task']) ? $task['.task'] : []
            : [];

        $attributes['prefix'] = $prefix;
        $attributes['indent'] = $indent;

        if ($this->output->isDebug()) {
            $this->log('<comment>[attributes]</comment>', $indent + 1);
            $this->log(print_r($attributes, 1), $indent + 2);
        }

        $Task->configure($attributes);

        $EventTask = new TaskEvent($this, $Task, $indent + 1);

        if (!$this->raiseEvent(static::EVENT_AFTER_CREATE_TASK_OBJECT, $EventTask)) {
            return false;
        }

        if (isset($task['.depends']) && !empty($task['.depends'])) {
            if ($this->output->isVerbose()) {
                $this->log('<comment>[depends]</comment>', $indent + 1);
                $chunks = array_chunk($task['.depends'], 4);
                foreach ($chunks as $chunk) {
                    $this->log(implode(', ', $chunk), $indent + 2);
                }
            }

            foreach ($task['.depends'] as $depend_name) {
                $depend = $this->findTask($depend_name);
                $result = $this->executeTask($depend, $prefix . '[' . $depend_name . ']', $indent + 1);

                if (false === $result) {
                    $this->log('<task-error> TASK </task-error> <error>' . $prefix . '[' . $depend_name . '] failure.</error>');

                    return false;
                }
            }
        }

        if (!$this->raiseEvent(static::EVENT_BEFORE_EXECUTE_TASK, $EventTask)) {
            return false;
        }

        $result = $Task->run();

        if (!$this->raiseEvent(static::EVENT_AFTER_EXECUTE_TASK, $EventTask)) {
            return false;
        }

        return $result;
    }

    /**
     * @param string $event
     * @param TaskEvent $Event
     * @return bool
     */
    private function raiseEvent($event, TaskEvent $Event)
    {
        $disable_events = (string)$this->input->getOption('disable-events');

        if (in_array($disable_events, ['yes', 'force', 'always', 'true', '1'], true)) {
            return true;
        }

        if ($this->output->isVeryVerbose()) {
            $this->log(sprintf('Raise event %s', $event), $Event->getIndent());
        }

        $this->eventDispatcher->dispatch($event, $Event);

        if ($Event->isPropagationStopped()) {
            $this->log(sprintf('<error>Event %s: Propagation stopped.</error>', $event), $Event->getIndent());

            return false;
        }

        return true;
    }

    /**
     * @param float $started_at
     */
    private function footer($started_at)
    {
        $delta = round(microtime(true) - $started_at);

        if ($delta <= 0) {
            $time = 'momentarily';
        } else {
            $hours = floor($delta / 3600);
            $minutes = floor(($delta - $hours * 3600) / 60);
            $seconds = $delta - $hours * 3600 - $minutes * 60;

            $time = [
                $hours > 0 ? ($hours . 'h') : null,
                $minutes > 0 ? ($minutes . 'm') : null,
                $seconds > 0 ? ($seconds . 's') : null,
            ];

            $time = implode(' ', $time);
        }

        $this->log("\n" . '<task-result> RES </task-result> <comment>Build finished.</comment>');
        $this->log('<task-result> RES </task-result> <comment>Total time: ' . $time . '</comment>');
    }

    /**
     * @param string $task_name
     */
    private function detectLoop($task_name)
    {
        $loop_threshold = (int)$this->input->getOption('loop-threshold');
        $loop_threshold = $loop_threshold <= 0 ? 3 : $loop_threshold;

        if (!isset($this->executed[$task_name])) {
            $this->executed[$task_name] = 0;
        }

        $this->executed[$task_name]++;

        if ($this->executed[$task_name] >= $loop_threshold) {
            $executed = array_flip($this->executed);
            ksort($executed);

            throw new \RuntimeException('Loop detected (' . array_pop($executed) . ' <> ' . array_pop($executed) . ').');
        }
    }

    private function readConfig()
    {
        $this->configReader = $this->getConfigReader();
        $this->rawConfig = $this->configReader->read();
        $this->config = $this->configReader->expandConfig($this->rawConfig);
    }

    private function registerEventListeners()
    {
        if (isset($this->rawConfig['.events']) && isset($this->rawConfig['.events']['subscribers'])) {
            $subscribers = $this->rawConfig['.events']['subscribers'];

            if (is_array($subscribers) && !empty($subscribers)) {
                foreach ($subscribers as $subscriberClass) {
                    $this->eventDispatcher->addSubscriber(new $subscriberClass);
                }
            }
        }

        if (isset($this->rawConfig['.events']) && isset($this->rawConfig['.events']['listeners'])) {
            $listeners = $this->rawConfig['.events']['listeners'];

            if (is_array($listeners) && !empty($listeners)) {
                foreach ($listeners as $eventName => $listener) {
                    $this->eventDispatcher->addListener($eventName, $listener);
                }
            }
        }
    }

    /**
     * @return \cookyii\build\config\AbstractConfigReader
     */
    private function getConfigReader()
    {
        switch ($this->input->getOption('config-type')) {
            default:
            case 'default':
                $result = new \cookyii\build\config\DefaultConfigReader($this);
                break;
            case 'phing':
                $result = new \cookyii\build\config\PhingConfigReader($this);
                break;
            case 'json':
                $result = new \cookyii\build\config\JsonConfigReader($this);
                break;
        }

        return $result;
    }

    private function setStyles()
    {
        $color = $this->input->getOption('color');

        $Formatter = $this->output
            ->getFormatter();

        $Formatter->setStyle('error', new Console\Formatter\OutputFormatterStyle('red', null));
        $Formatter->setStyle('task', new Console\Formatter\OutputFormatterStyle('black', 'blue'));
        $Formatter->setStyle('task-error', new Console\Formatter\OutputFormatterStyle('black', 'red'));
        $Formatter->setStyle('task-result', new Console\Formatter\OutputFormatterStyle('black', 'yellow'));
        $Formatter->setStyle('header', new Console\Formatter\OutputFormatterStyle(null, null, ['bold']));

        if (in_array($color, ['no', 'none', 'never'], true)) {
            $defaultStyle = new Console\Formatter\OutputFormatterStyle();

            $Formatter->setStyle('error', $defaultStyle);
            $Formatter->setStyle('info', $defaultStyle);
            $Formatter->setStyle('comment', $defaultStyle);
            $Formatter->setStyle('question', $defaultStyle);
            $Formatter->setStyle('task', $defaultStyle);
            $Formatter->setStyle('task-error', $defaultStyle);
            $Formatter->setStyle('task-result', $defaultStyle);
            $Formatter->setStyle('header', $defaultStyle);
        }
    }

    /**
     * @return string
     */
    public function getDelimiter()
    {
        $delimiter = (string)$this->input->getOption('task-delimiter');

        return empty($delimiter) ? '/' : $delimiter;
    }
}