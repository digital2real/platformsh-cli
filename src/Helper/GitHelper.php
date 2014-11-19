<?php

namespace CommerceGuys\Platform\Cli\Helper;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Process;

class GitHelper extends Helper
{

    /** @var string */
    protected $repositoryDir = '.';

    /** @var OutputInterface|false */
    protected $output;

    /**
     * @inheritdoc
     */
    public function getName()
    {
        return 'git';
    }

    /**
     * @param OutputInterface|false $output
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * @param int    $type
     * @param string $buffer
     */
    public function log($type, $buffer)
    {
        if ($this->output) {
            $this->output->write($buffer);
        }
    }

    /**
     * Set the repository directory.
     *
     * The default is the current working directory.
     *
     * @param string $dir
     *   The path to a Git repository.
     */
    public function setDefaultRepositoryDir($dir)
    {
        $this->repositoryDir = $dir;
    }

    /**
     * Get the current branch name.
     *
     * @param string $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return string|false
     */
    public function getCurrentBranch($dir = null, $mustRun = false)
    {
        $args = array('symbolic-ref', '--short', 'HEAD');

        return $this->execute($args, $dir, $mustRun);
    }

    /**
     * Execute a Git command.
     *
     * @param string|false $dir
     *   The path to a Git repository. Set to false if the command should not
     *   run inside a repository.
     * @param string[]     $args
     *   Command arguments (everything after 'git').
     * @param bool         $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @throws \RuntimeException If the repository directory is invalid.
     * @throws ProcessFailedException If $mustRun is enabled and the command fails.
     *
     * @return string|bool
     */
    public function execute(array $args, $dir = null, $mustRun = false)
    {
        array_unshift($args, 'git');
        $process = $this->buildProcess($args);
        // If enabled, set the working directory to the repository.
        if ($dir !== false) {
            $dir = $dir ?: $this->repositoryDir;
            if ($args[1] != 'init' && !$this->isRepository($dir)) {
                throw new \RuntimeException("Not a Git repository: " . $dir);
            }
            $process->setWorkingDirectory($dir);
        }
        // Run the command.
        try {
            $process->mustRun(array($this, 'log'));
        } catch (ProcessFailedException $e) {
            if (!$mustRun) {
                return false;
            }
            throw $e;
        }
        $output = $process->getOutput();

        return $output ? rtrim($output) : true;
    }

    /**
     * @param array $args
     * @return Process
     */
    protected function buildProcess(array $args)
    {
        $processBuilder = new ProcessBuilder($args);
        return $processBuilder->getProcess();
    }

    /**
     * Create a Git repository in a directory.
     *
     * @param string $dir
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function init($dir, $mustRun = false)
    {
        if ($this->isRepository($dir)) {
            throw new \InvalidArgumentException("Already a repository: $dir");
        }

        return (bool) $this->execute(array('init'), $dir, $mustRun);
    }

    /**
     * Check whether a directory is a Git repository.
     *
     * @param string $dir
     *
     * @return bool
     */
    public function isRepository($dir)
    {
        return is_dir($dir . '/.git');
    }

    /**
     * Check whether a branch exists.
     *
     * @param string $branchName
     *   The branch name.
     * @param string $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function branchExists($branchName, $dir = null, $mustRun = false)
    {
        $args = array('show-ref', "refs/heads/$branchName");

        return (bool) $this->execute($args, $dir, $mustRun);
    }

    /**
     * Create a new branch.
     *
     * @param string $name
     * @param string $parent
     * @param string $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function branch($name, $parent = null, $dir = null, $mustRun = false)
    {
        $args = array('checkout', '-b', $name);
        if ($parent) {
            $args[] = $parent;
        }
        return (bool) $this->execute($args, $dir, $mustRun);
    }

    /**
     * Check out a branch.
     *
     * @param string $name
     * @param string $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function checkOut($name, $dir = null, $mustRun = false)
    {
        return (bool) $this->execute(array('checkout', $name), $dir, $mustRun);
    }

    /**
     * Get the upstream for the current branch.
     *
     * @param string $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return string|false
     */
    public function getUpstream($dir = null, $mustRun = false)
    {
        $args = array('rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}');

        return $this->execute($args, $dir, $mustRun);
    }

    /**
     * Clone a repository.
     *
     * A ProcessFailedException will be thrown if the command fails.
     *
     * @param string $url
     *   The Git repository URL.
     * @param string $destination
     *   A directory name to clone into.
     * @param string $branch
     *   The name of a branch to clone.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function cloneRepo($url, $destination = null, $branch = null, $mustRun = false)
    {
        $args = array('clone', $url);
        if ($destination) {
            $args[] = $destination;
        }
        if ($branch) {
            $args[] = '--branch';
            $args[] = $branch;
        }

        return (bool) $this->execute($args, false, $mustRun);
    }

    /**
     * Read a configuration item.
     *
     * @param string $key
     *   A Git configuration key.
     * @param string $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return string|false
     */
    public function getConfig($key, $dir = null, $mustRun = false)
    {
        $args = array('config', '--get', $key);

        return $this->execute($args, $dir, $mustRun);
    }

}
