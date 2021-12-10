<?php

namespace ColinHDev\VanillaHopper\entities;

use ColinHDev\VanillaHopper\blocks\BlockUpdateScheduler;
use ColinHDev\VanillaHopper\blocks\tiles\Hopper as TileHopper;
use ColinHDev\VanillaHopper\blocks\Hopper;
use ColinHDev\VanillaHopper\VanillaHopper;
use pocketmine\entity\object\ItemEntity;
use pocketmine\scheduler\CancelTaskException;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\Server;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\format\Chunk;
use pocketmine\world\Position;
use pocketmine\world\World;

final class ItemEntityManager {
    use SingletonTrait;

    private ?TaskHandler $taskHandler = null;
    /** @var array<int, ItemEntity> */
    private array $entities = [];
    /** @var array<int, Position> */
    private array $lastEntityPositions = [];
    /** @var array<int, array<int, array<int, ItemEntity>>> */
    private array $entitiesByHopper = [];

    public function addItemEntity(ItemEntity $entity) : void {
        $entityID = $entity->getId();
        $this->entities[$entityID] = $entity;
        $this->scheduleTask();
    }

    public function removeItemEntity(ItemEntity $entity) : void {
        $entityID = $entity->getId();
        unset($this->entities[$entityID]);
        unset($this->lastEntityPositions[$entityID]);
    }

    /**
     * @return array<int, ItemEntity>
     */
    public function getEntitiesByHopper(Hopper $block) : array {
        $position = $block->getPosition();
        $worldID = $position->world->getId();
        if (!isset($this->entitiesByHopper[$worldID])) {
            return [];
        }
        $blockHash = World::blockHash((int) floor($position->x), (int) floor($position->y), (int) floor($position->z));
        if (!isset($this->entitiesByHopper[$worldID][$blockHash])) {
            return [];
        }
        return $this->entitiesByHopper[$worldID][$blockHash];
    }

    public function removeEntityFromHopper(Hopper $block, ItemEntity $entity) : void {
        $position = $block->getPosition();
        $blockHash = World::blockHash((int) floor($position->x), (int) floor($position->y), (int) floor($position->z));
        unset($this->entitiesByHopper[$position->world->getId()][$blockHash][$entity->getId()]);
    }

    public function scheduleTask() : void {
        if ($this->taskHandler !== null) {
            return;
        }
        $this->taskHandler = VanillaHopper::getInstance()->getScheduler()->scheduleDelayedRepeatingTask(
            new ClosureTask(
                function() {
                    if (empty($this->entities)) {
                        $this->taskHandler = null;
                        throw new CancelTaskException();
                    }

                    foreach ($this->entities as $entityID => $entity) {
                        $position = $entity->getPosition();
                        if (isset($this->lastEntityPositions[$entityID])) {
                            $lastPosition = $this->lastEntityPositions[$entityID];
                            if ($position->world === $lastPosition->world && $position->distance($lastPosition) >= 1.0) {
                                continue;
                            }
                        }

                        $this->lastEntityPositions[$entityID] = $position;

                        $block = $position->world->getBlock($position);
                        $tile = $position->world->getTile($position);
                        if (!$block instanceof Hopper || !$tile instanceof TileHopper) {
                            $block = $position->world->getBlock($position->down());
                            $tile = $position->world->getTile($position->down());
                            if (!$block instanceof Hopper || !$tile instanceof TileHopper) {
                                continue;
                            }
                        }
                        $position = $block->getPosition();

                        if (!$tile->isScheduledForDelayedBlockUpdate()) {
                            $tile->setTransferCooldown(
                                BlockUpdateScheduler::getInstance()->scheduleDelayedBlockUpdate(
                                    $position->world,
                                    $position,
                                    0
                                )
                            );
                            $tile->setScheduledForDelayedBlockUpdate(true);
                        }

                        $worldID = $position->world->getId();
                        if (!isset($this->entitiesByHopper[$worldID])) {
                            $this->entitiesByHopper[$worldID] = [];
                        }
                        $blockHash = World::blockHash($position->x, $position->y, $position->z);
                        if (!isset($this->entitiesByHopper[$worldID][$blockHash])) {
                            $this->entitiesByHopper[$worldID][$blockHash] = [];
                        }
                        $this->entitiesByHopper[$worldID][$blockHash][$entityID] = $entity;
                    }
                    // Unlike Java Edition, Bedrock Edition's hoppers don't save in which order item entities landed on top of them to collect them in that order.
                    // In Bedrock Edition hoppers collect item entities in the order in which they entered the chunk.
                    foreach ($this->entitiesByHopper as $worldID => $entities) {
                        $world = VanillaHopper::getInstance()->getServer()->getWorldManager()->getWorld($worldID);
                        foreach ($entities as $blockHash => $hopperEntities) {
                            World::getBlockXYZ($blockHash, $x, $y, $z);
                            $chunkX = $x >> Chunk::COORD_BIT_SIZE;
                            $chunkZ = $z >> Chunk::COORD_BIT_SIZE;
                            uksort(
                                $hopperEntities,
                                function (int $entityID1, int $entityID2) use ($world, $chunkX, $chunkZ) : int {
                                    $chunkEntities = array_keys($world->getChunkEntities($chunkX, $chunkZ));
                                    return array_search($entityID1, $chunkEntities, true) > array_search($entityID2, $chunkEntities, true) ? 1 : -1;
                                }
                            );
                        }
                    }
                }
            ),
            1,
            1
        );
    }
}