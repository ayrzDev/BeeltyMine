<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 *
 * @author rockanxy
 * @team BeeltyMine
 * @link http://www.beeltymine.net/
 */

declare(strict_types=1);

namespace pocketmine\block\utils;

use pocketmine\block\Hopper as HopperBlock;
use pocketmine\block\inventory\BlockInventory;
use pocketmine\block\inventory\DoubleChestInventory;
use pocketmine\entity\object\ItemEntity;
use pocketmine\inventory\Inventory;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\Server;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\Position;
use pocketmine\world\World;
use function array_key_last;
use function count;
use function max;
use function min;
use function usort;

final class HopperRuntime{
	use SingletonTrait;

	private const CONFIG_TRANSFER_COOLDOWN = 'hopper.transferCooldown';
	private const CONFIG_ITEMS_PER_UPDATE = 'hopper.itemsPerUpdate';
	private const CONFIG_UPDATES_PER_TICK = 'hopper.updatesPerTick';
	private const CONFIG_ITEM_DESPAWN_SECONDS = 'item.despawnSeconds';
	private const CONFIG_ITEM_MAX_PER_WORLD = 'item.maxPerWorld';

	private bool $configLoaded = false;
	private int $defaultTransferCooldown = 16;
	private int $itemsPerUpdate = 64;
	private int $updatesPerTickLimit = 75;
	private int $itemDespawnSeconds = 60;
	private int $itemMaxPerWorld = 200;
	private int $currentTick = 0;
	/** @var array<int, int> */
	private array $updatesPerTick = [];

	public function getDefaultTransferCooldown(Server $server) : int{
		$this->loadConfig($server);
		return $this->defaultTransferCooldown;
	}

	public function getItemsPerUpdate(Server $server) : int{
		$this->loadConfig($server);
		return $this->itemsPerUpdate;
	}

	public function getUpdatesPerTick(Server $server) : int{
		$this->loadConfig($server);
		return $this->updatesPerTickLimit;
	}

	public function getItemDespawnTicks(Server $server) : int{
		$this->loadConfig($server);
		return $this->itemDespawnSeconds > 0 ? $this->itemDespawnSeconds * 20 : 0;
	}

	public function getItemMaxPerWorld(Server $server) : int{
		$this->loadConfig($server);
		return $this->itemMaxPerWorld;
	}

	public function scheduleDelayedBlockUpdate(World $world, Vector3 $vector3, int $preferredDelay) : int{
		$this->loadConfig($world->getServer());

		$actualDelay = max(0, $preferredDelay);
		if($this->updatesPerTickLimit > 0){
			$currentTick = $world->getServer()->getTick();
			if($this->currentTick !== $currentTick){
				$this->currentTick = $currentTick;
				foreach($this->updatesPerTick as $tick => $updates){
					if($tick < $currentTick){
						unset($this->updatesPerTick[$tick]);
						continue;
					}
					break;
				}
			}

			$delayTick = $currentTick + $actualDelay;
			if(!isset($this->updatesPerTick[$delayTick])){
				$this->updatesPerTick[$delayTick] = 1;
			}elseif($this->updatesPerTick[$delayTick] < $this->updatesPerTickLimit){
				++$this->updatesPerTick[$delayTick];
			}else{
				foreach($this->updatesPerTick as $tick => $updates){
					if($tick <= $delayTick || $updates >= $this->updatesPerTickLimit){
						continue;
					}

					$delayTick = $tick;
					$actualDelay = $delayTick - $currentTick;
					break;
				}

				if($actualDelay === $preferredDelay){
					$lastScheduledTick = array_key_last($this->updatesPerTick);
					$delayTick = ($lastScheduledTick ?? $currentTick) + 1;
					$this->updatesPerTick[$delayTick] = 1;
					$actualDelay = $delayTick - $currentTick;
				}else{
					++$this->updatesPerTick[$delayTick];
				}
			}
		}

		$world->scheduleDelayedBlockUpdate($vector3, $actualDelay);
		return $actualDelay;
	}

	/**
	 * @param Inventory[] $inventories
	 * @phpstan-param array<int, Inventory> $inventories
	 */
	public function scheduleHoppersForInventories(array $inventories) : void{
		$positions = [];
		foreach($inventories as $inventory){
			if(!$inventory instanceof BlockInventory){
				continue;
			}

			$holder = $inventory->getHolder();
			if($holder->isValid()){
				$positions[$this->getPositionKey($holder)] = $holder;
			}

			if($inventory instanceof DoubleChestInventory){
				$rightHolder = $inventory->getRightSide()->getHolder();
				if($rightHolder->isValid()){
					$positions[$this->getPositionKey($rightHolder)] = $rightHolder;
				}
			}
		}

		foreach($positions as $position){
			$this->scheduleHoppersAround($position, [Facing::DOWN, Facing::UP, Facing::NORTH, Facing::SOUTH, Facing::WEST, Facing::EAST], true);
		}
	}

	/**
	 * @param int[] $facings
	 * @phpstan-param array<int, int> $facings
	 */
	public function scheduleHoppersAround(Position $position, array $facings, bool $includeSelf = false, int $delay = 1) : void{
		if(!$position->isValid()){
			return;
		}

		$scheduled = [];
		if($includeSelf){
			$scheduled[$this->getPositionKey($position)] = true;
			$this->scheduleHopperAt($position, $delay);
		}

		foreach($facings as $facing){
			$side = $position->getSide($facing);
			$key = $this->getPositionKey($side);
			if(isset($scheduled[$key])){
				continue;
			}

			$scheduled[$key] = true;
			$this->scheduleHopperAt($side, $delay);
		}
	}

	public function scheduleHopperAt(Position $position, int $delay = 1) : void{
		if(!$position->isValid()){
			return;
		}

		$block = $position->getWorld()->getBlock($position);
		if($block instanceof HopperBlock){
			$block->scheduleDelayedBlockUpdate($delay);
		}
	}

	public function onServerTick(Server $server) : void{
		$this->loadConfig($server);
		if($this->itemMaxPerWorld <= 0 || ($server->getTick() % 200) !== 0){
			return;
		}

		foreach(ItemEntity::getOverflowWorlds($this->itemMaxPerWorld) as $items){
			if(count($items) <= $this->itemMaxPerWorld){
				continue;
			}

			$world = null;
			foreach($items as $entity){
				if(!$entity->isClosed() && !$entity->isFlaggedForDespawn()){
					$world = $entity->getWorld();
					break;
				}
			}

			if($world === null){
				continue;
			}

			usort($items, static fn(ItemEntity $left, ItemEntity $right) : int => $left->getDespawnDelay() <=> $right->getDespawnDelay());

			$toRemove = count($items) - $this->itemMaxPerWorld;
			for($i = 0; $i < $toRemove; ++$i){
				$items[$i]->flagForDespawn();
			}

			$server->getLogger()->debug(
				"[ItemCleanup] {$world->getFolderName()}: " . count($items) . ' item entity -> ' . min($toRemove, count($items)) . ' tanesi temizlendi.'
			);
		}
	}

	private function loadConfig(Server $server) : void{
		if($this->configLoaded){
			return;
		}

		$config = $server->getConfigGroup();
		$this->defaultTransferCooldown = max(1, (int) $config->getProperty(self::CONFIG_TRANSFER_COOLDOWN, 16));
		$this->itemsPerUpdate = max(1, (int) $config->getProperty(self::CONFIG_ITEMS_PER_UPDATE, 64));
		$this->updatesPerTickLimit = (int) $config->getProperty(self::CONFIG_UPDATES_PER_TICK, 75);
		$this->itemDespawnSeconds = max(0, (int) $config->getProperty(self::CONFIG_ITEM_DESPAWN_SECONDS, 60));
		$this->itemMaxPerWorld = max(0, (int) $config->getProperty(self::CONFIG_ITEM_MAX_PER_WORLD, 200));
		$this->configLoaded = true;
	}

	private function getPositionKey(Position $position) : string{
		return $position->getWorld()->getId() . ':' . $position->getFloorX() . ':' . $position->getFloorY() . ':' . $position->getFloorZ();
	}
}