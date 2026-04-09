<?php

/*
 *     ____            ____        __  ____
 *    / __ )___  ___  / / /___  __/  |/  (_)___  ___
 *   / __  / _ \/ _ \/ / __/ / / / /|_/ / / __ \/ _ \
 *  / /_/ /  __/  __/ / /_/ /_/ / /  / / / / / /  __/
 * /_____/\___/\___/_/\__/\__, /_/  /_/_/_/ /_/\___/
 *                       /____/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Ayrz
 *
 */

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\block\Beehive as BlockBeehive;
use pocketmine\block\BeeNest as BlockBeeNest;
use pocketmine\entity\Bee;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\world\sound\BeehiveExitSound;
use pocketmine\world\sound\BeehiveWorkSound;
use pocketmine\world\World;
use function count;
use function max;
use function mt_rand;

class Beehive extends Spawnable{

	private const TAG_BEES = "Bees";
	private const TAG_BEE_DATA = "EntityData";
	private const TAG_TICKS_IN_HIVE = "TicksInHive";
	private const TAG_MIN_TICKS = "MinOccupationTicks";

	private const MAX_BEE_COUNT = 3;
	private const MIN_TICKS_IN_HIVE = 2400;
	private const TICK_INTERVAL = 20;
	private const WORK_SOUND_MIN_DELAY = 100;
	private const WORK_SOUND_MAX_DELAY = 300;

	/** @var CompoundTag[] */
	private array $bees = [];
	private bool $silkTouched = false;
	private ?Entity $angryTarget = null;
	private int $workSoundCooldown = 0;

	public function readSaveData(CompoundTag $nbt) : void{
		$this->bees = [];
		$beesTag = $nbt->getListTag(self::TAG_BEES);
		if($beesTag !== null){
			foreach($beesTag as $beeTag){
				if($beeTag instanceof CompoundTag){
					$this->bees[] = clone $beeTag;
				}
			}
		}
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$list = new ListTag();
		foreach($this->bees as $bee){
			$list->push(clone $bee);
		}
		$nbt->setTag(self::TAG_BEES, $list);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$nbt->setInt("Occupants", count($this->bees));
	}

	public function getBeeCount() : int{
		return count($this->bees);
	}

	public function isFull() : bool{
		return count($this->bees) >= self::MAX_BEE_COUNT;
	}

	/** @return CompoundTag[] */
	public function getStoredBees() : array{
		return $this->bees;
	}

	public function addBee(CompoundTag $beeNbt, bool $hasNectar) : bool{
		if($this->isFull()){
			return false;
		}

		$entry = CompoundTag::create()
			->setTag(self::TAG_BEE_DATA, $beeNbt)
			->setInt(self::TAG_TICKS_IN_HIVE, 0)
			->setInt(self::TAG_MIN_TICKS, $hasNectar ? self::MIN_TICKS_IN_HIVE : 600);
		$this->bees[] = $entry;

		$this->scheduleUpdate();
		$this->clearSpawnCompoundCache();
		return true;
	}

	private function scheduleUpdate() : void{
		if($this->position->isValid()){
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, self::TICK_INTERVAL);
		}
	}

	private function isNightTime() : bool{
		$time = $this->position->getWorld()->getTimeOfDay();
		return $time >= World::TIME_SUNSET && $time < World::TIME_SUNRISE;
	}

	public function onUpdate() : bool{
		if($this->closed || count($this->bees) === 0){
			return false;
		}

		$this->timings->startTiming();

		$world = $this->position->getWorld();
		$this->workSoundCooldown -= self::TICK_INTERVAL;
		if($this->workSoundCooldown <= 0){
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new BeehiveWorkSound());
			$this->workSoundCooldown = mt_rand(self::WORK_SOUND_MIN_DELAY, self::WORK_SOUND_MAX_DELAY);
		}

		$released = false;
		$remaining = [];

		foreach($this->bees as $entry){
			$ticks = $entry->getInt(self::TAG_TICKS_IN_HIVE) + self::TICK_INTERVAL;
			$minTicks = $entry->getInt(self::TAG_MIN_TICKS);

			if(!$released && $ticks >= $minTicks){
				if($this->tryReleaseBee($entry)){
					$released = true;
					continue;
				}
			}

			$entry->setInt(self::TAG_TICKS_IN_HIVE, $ticks);
			$remaining[] = $entry;
		}

		$this->bees = $remaining;

		if($released){
			$this->clearSpawnCompoundCache();
		}

		$this->timings->stopTiming();

		return count($this->bees) > 0;
	}

	private function tryReleaseBee(CompoundTag $entry) : bool{
		if($this->isNightTime()){
			return false;
		}

		$world = $this->position->getWorld();
		$pos = $this->position;

		$spawnPos = $this->findSpawnPosition($world, $pos);
		if($spawnPos === null){
			return false;
		}

		$beeNbt = $entry->getCompoundTag(self::TAG_BEE_DATA);
		if($beeNbt === null){
			return true;
		}

		$hadNectar = false;
		$properties = $beeNbt->getCompoundTag("properties");
		if($properties !== null && $properties->getTag("minecraft:has_nectar") !== null){
			$hadNectar = $properties->getByte("minecraft:has_nectar", 0) !== 0;
		}else{
			$hadNectar = $beeNbt->getByte("HasNectar", 0) !== 0;
		}
		$beeNbt->setByte("HasNectar", 0);
		$beeNbt->setInt("AngerTime", 0);
		if($properties !== null){
			$properties->setByte("minecraft:has_nectar", 0);
		}

		$location = Location::fromObject(
			$spawnPos,
			$world,
			mt_rand(0, 360) * 1.0,
			0.0
		);

		$bee = new Bee($location, $beeNbt);
		$bee->spawnToAll();

		$world->addSound($spawnPos, new BeehiveExitSound());

		$block = $world->getBlockAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), false, false);
		if($hadNectar && ($block instanceof BlockBeehive || $block instanceof BlockBeeNest)){
			$newLevel = $block->getHoneyLevel() + 1;
			if($newLevel <= 5){
				$world->setBlockAt(
					$pos->getFloorX(),
					$pos->getFloorY(),
					$pos->getFloorZ(),
					$block->setHoneyLevel($newLevel),
					true
				);
			}
		}

		return true;
	}

	private function findSpawnPosition(World $world, Vector3 $pos) : ?Vector3{
		$offsets = [
			new Vector3(1, 0, 0), new Vector3(-1, 0, 0),
			new Vector3(0, 0, 1), new Vector3(0, 0, -1),
			new Vector3(0, 1, 0), new Vector3(1, 0, 1),
		];

		foreach($offsets as $offset){
			$check = $pos->addVector($offset)->add(0.5, 0.0, 0.5);
			$bx = (int) $check->x;
			$by = (int) $check->y;
			$bz = (int) $check->z;
			if(
				$world->isInWorld($bx, $by, $bz) &&
				!$world->getBlockAt($bx, $by, $bz)->isSolid()
			){
				return $check;
			}
		}

		return null;
	}

	public function setSilkTouched(bool $value) : void{
		$this->silkTouched = $value;
	}

	public function setAngryTarget(?Entity $target) : void{
		$this->angryTarget = $target;
	}

	public function releaseAngryBees(Entity $target) : void{
		$world = $this->position->getWorld();
		$remaining = [];
		foreach($this->bees as $entry){
			$beeNbt = $entry->getCompoundTag(self::TAG_BEE_DATA);
			if($beeNbt === null){
				continue;
			}
			$spawnPos = $this->findSpawnPosition($world, $this->position);
			if($spawnPos === null){
				$remaining[] = $entry;
				continue;
			}
			$location = Location::fromObject($spawnPos, $world, mt_rand(0, 360) * 1.0, 0.0);
			$bee = new Bee($location, $beeNbt);
			$bee->spawnToAll();
			if(!$target->isClosed() && $target->isAlive()){
				$bee->setAngry($target);
			}
		}
		$this->bees = $remaining;
		$this->clearSpawnCompoundCache();
		if(!$target->isClosed() && $target->isAlive()){
			Bee::alertNearbyBees($target, $this->position, $world);
		}
	}

	protected function onBlockDestroyedHook() : void{
		if($this->silkTouched){
			return;
		}
		$world = $this->position->getWorld();
		foreach($this->bees as $entry){
			$beeNbt = $entry->getCompoundTag(self::TAG_BEE_DATA);
			if($beeNbt === null){
				continue;
			}

			$spawnPos = $this->position->add(0.5, 0.0, 0.5);
			$location = Location::fromObject($spawnPos, $world, mt_rand(0, 360) * 1.0, 0.0);
			$bee = new Bee($location, $beeNbt);
			$bee->spawnToAll();

			if($this->angryTarget !== null && !$this->angryTarget->isClosed() && $this->angryTarget->isAlive()){
				$bee->setAngry($this->angryTarget);
			}
		}
		if($this->angryTarget !== null && !$this->angryTarget->isClosed() && $this->angryTarget->isAlive()){
			Bee::alertNearbyBees($this->angryTarget, $this->position, $world);
		}
		$this->bees = [];
	}
}
