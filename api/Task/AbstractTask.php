<?php

/*
 * This file is part of Contao Manager.
 *
 * (c) Contao Association
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\ManagerApi\Task;

use Contao\ManagerApi\I18n\Translator;
use Contao\ManagerApi\TaskOperation\TaskOperationInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

abstract class AbstractTask implements TaskInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var Translator
     */
    protected $translator;

    /**
     * @var TaskOperationInterface[]
     */
    private $operations;

    /**
     * Constructor.
     *
     * @param Translator           $translator
     * @param LoggerInterface|null $logger
     */
    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    /**
     * {@inheritdoc}
     */
    public function create(TaskConfig $config)
    {
        return (new TaskStatus($this->translator->trans('task.'.$this->getName().'.title')))
            ->setSummary($this->translator->trans('taskstatus.created'));
    }

    /**
     * {@inheritdoc}
     */
    public function update(TaskConfig $config)
    {
        if ($config->isCancelled()) {
            return $this->abort($config);
        }

        $status = $this->create($config);
        $status->setStatus(TaskStatus::STATUS_ACTIVE);

        foreach ($this->getOperations($config) as $operation) {
            if (!$operation->isStarted() || $operation->isRunning()) {
                if (null !== $this->logger) {
                    $this->logger->info('Current operation: '.get_class($operation));
                }

                $operation->run();

                if ($operation->hasError()) {
                    $status->setStatus(TaskStatus::STATUS_ERROR);

                    if (null !== $this->logger) {
                        $this->logger->info('Failed operation: '.get_class($operation));
                    }
                }

                $operation->updateStatus($status);
                $this->updateStatus($status);

                return $status;
            }

            $operation->updateStatus($status);

            if ($operation->isSuccessful()) {
                if (null !== $this->logger) {
                    $this->logger->info('Completed operation: '.get_class($operation));
                }

                continue;
            }

            $status->setStatus(TaskStatus::STATUS_ERROR);
            $this->updateStatus($status);

            return $status;
        }

        $status->setStatus(TaskStatus::STATUS_COMPLETE);

        $this->updateStatus($status);

        return $status;
    }

    /**
     * {@inheritdoc}
     */
    public function abort(TaskConfig $config)
    {
        $config->setCancelled();

        $status = $this->create($config);
        $status->setStatus(TaskStatus::STATUS_STOPPED);

        foreach ($this->getOperations($config) as $operation) {
            $operation->abort();
            $operation->updateStatus($status);

            if ($operation->isRunning()) {
                $status->setStatus(TaskStatus::STATUS_ABORTING);

                if (null !== $this->logger) {
                    $this->logger->info('Task operation is active, aborting', ['class' => get_class($operation)]);
                }
                break;
            }

            if ($operation->isSuccessful()) {
                if (null !== $this->logger) {
                    $this->logger->info('Task operation is completed, continuing', ['class' => get_class($operation)]);
                }

                continue;
            }

            break;
        }

        $this->updateStatus($status);

        return $status;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(TaskConfig $config)
    {
        $operations = $this->getOperations($config);

        foreach ($operations as $operation) {
            if ($operation->isRunning()) {
                if (null !== $this->logger) {
                    $this->logger->info('Cannot delete active operation', ['class' => get_class($operation)]);
                }

                return false;
            }
        }

        foreach ($operations as $operation) {
            $operation->delete();

            if (null !== $this->logger) {
                $this->logger->info('Deleting operation', ['class' => get_class($operation)]);
            }
        }

        $config->delete();

        return true;
    }

    protected function getOperations(TaskConfig $config)
    {
        if (null === $this->operations) {
            $this->operations = $this->buildOperations($config);

            foreach ($this->operations as $operation) {
                if (null !== $this->logger && $operation instanceof LoggerAwareInterface) {
                    $operation->setLogger($this->logger);
                }
            }
        }

        return $this->operations;
    }

    /**
     * @param TaskStatus $status
     */
    protected function updateStatus(TaskStatus $status)
    {
        $result = $status->getStatus();

        if ($result === TaskStatus::STATUS_ACTIVE) {
            return;
        }

        $status->setSummary($this->translator->trans(sprintf('taskstatus.%s.summary', $result)));
        $status->setDetail($this->translator->trans(sprintf('taskstatus.%s.detail', $result)));
    }

    /**
     * @param TaskConfig $config
     *
     * @return TaskOperationInterface[]
     */
    abstract protected function buildOperations(TaskConfig $config);
}
