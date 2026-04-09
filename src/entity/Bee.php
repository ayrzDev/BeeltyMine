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
 * @author bonbionTR
 * @team BeeltyMine
 * 
 * 
 */

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\block\Beehive;
use pocketmine\block\BeeNest;
use pocketmine\block\DoublePlant;
use pocketmine\block\Flower;
use pocketmine\block\tile\Beehive as TileBeehive;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\SpawnEgg;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\player\Player;
use pocketmine\entity\animation\BeeStingAnimation;
use pocketmine\world\sound\BeehiveEnterSound;
use pocketmine\world\sound\BeeStingSound;
use pocketmine\world\particle\HeartParticle;
use pocketmine\world\World;

use function abs;
use function atan2;
use function max;
use function min;
use function mt_rand;
use function sqrt;
use const M_PI;

class Bee extends Living implements Ageable{

	private static array $occupiedFlowers = [];

	private static function flowerKey(int $x, int $y, int $z) : string{
		return "$x:$y:$z";
	}

	private static function claimFlower(int $x, int $y, int $z, int $entityId) : bool{
		$key = self::flowerKey($x, $y, $z);
		if(isset(self::$occupiedFlowers[$key]) && self::$occupiedFlowers[$key] !== $entityId){
			return false;
		}
		self::$occupiedFlowers[$key] = $entityId;
		return true;
	}

	private static function releaseFlower(int $x, int $y, int $z, int $entityId) : void{
		$key = self::flowerKey($x, $y, $z);
		if(isset(self::$occupiedFlowers[$key]) && self::$occupiedFlowers[$key] === $entityId){
			unset(self::$occupiedFlowers[$key]);
		}
	}

	private static function isFlowerOccupied(int $x, int $y, int $z) : bool{
		return isset(self::$occupiedFlowers[self::flowerKey($x, $y, $z)]);
	}

	private const TAG_HAS_NECTAR = "HasNectar";
	private const TAG_PROPERTIES = "properties";
	private const TAG_PROPERTY_HAS_NECTAR = "minecraft:has_nectar";
	private const TAG_HOME_X = "HomeX";
	private const TAG_HOME_Y = "HomeY";
	private const TAG_HOME_Z = "HomeZ";
	private const TAG_HAS_STUNG = "HasStung";
	private const TAG_ANGER_TIME = "AngerTime";
	private const TAG_BABY = "Baby";
	private const TAG_AGE = "Age";
	private const TAG_LOVE_COOLDOWN = "LoveCooldown";

	private const FLOWER_SEARCH_INTERVAL = 80;
	private const HIVE_SEARCH_INTERVAL = 80;
	private const FLOWER_SEARCH_RADIUS = 8;
	private const HIVE_SEARCH_RADIUS = 20;
	private const SEARCH_VERTICAL_RANGE = 10;
	private const FLOWER_SEARCH_SAMPLES = 72;
	private const HIVE_SEARCH_SAMPLES = 96;
	private const POLLINATE_TICKS_REQUIRED = 400;
	private const FLOWER_REACH_SQ = 2.25;
	private const HIVE_REACH_SQ = 3.24;
	private const MAX_WANDER_DISTANCE_SQ = 484;

	private const FLY_SPEED = 0.10;
	private const FLY_SPEED_NECTAR = 0.08;
	private const FLY_SPEED_ANGRY = 0.16;
	private const FLY_GRAVITY_OFFSET = 0.04;
	private const FLY_DIRECTION_BLEND = 0.25;
	private const FLY_WANDER_INTERVAL = 80;
	private const FLY_HOVER_Y_VARIANCE = 0.01;
	private const FLY_HOVER_MIN_Y = 1.0;
	private const FLY_HOVER_MAX_Y = 4.0;
	private const FLY_MAX_SPEED = 0.12;
	private const FLY_MAX_Y_SPEED = 0.06;
	private const FLY_MAX_Y_SPEED_ANGRY = 0.14;

	private const ANGER_DURATION_TICKS = 500;
	private const STING_RANGE_SQ = 2.25;
	private const STING_DAMAGE = 2.0;
	private const STING_POISON_DURATION = 200;
	private const STING_DEATH_DELAY = 1100;
	private const NEARBY_BEE_ALERT_RADIUS = 16;

	private const SEPARATION_RADIUS = 1.5;
	private const SEPARATION_STRENGTH = 0.15;
	private const SEPARATION_UPDATE_INTERVAL = 10;
	private const SEPARATION_MAX_NEIGHBORS = 8;
	private const GROUND_CHECK_INTERVAL = 16;

	private const HEART_PARTICLE_INTERVAL = 10;
	private const FOLLOW_SCAN_INTERVAL = 12;
	private const BREED_SCAN_INTERVAL = 12;
	private const BABY_FOLLOW_SCAN_INTERVAL = 12;

	private const LOVE_MODE_DURATION = 600;
	private const BREED_COOLDOWN = 6000;
	private const BREED_RANGE_SQ = 3.0;
	private const BREED_KISS_TICKS = 40;
	private const BREED_KISS_HEART_INTERVAL = 8;
	private const BREED_XP_MIN = 1;
	private const BREED_XP_MAX = 7;
	private const BABY_GROW_TICKS = 24000;
	private const BABY_SCALE = 0.5;
	private const FOLLOW_FLOWER_RANGE = 8.0;
	private const FOLLOW_SPEED = 0.10;
	private const FOLLOW_MIN_DISTANCE = 1.25;
	private const FOLLOW_MIN_DISTANCE_SQ = 1.5625;
	private const FOLLOW_HOLD_DISTANCE_SQ = 3.24;
	private const FOLLOW_TARGET_OFFSET = 1.4;

	public static function getNetworkTypeId() : string{ return EntityIds::BEE; }

	protected bool $baby = false;
	private bool $hasNectar = false;
	private bool $hasStung = false;
	private bool $angry = false;

	private int $pollinateTicks = 0;
	private int $targetSearchCooldown = 0;
	private int $angerTicks = 0;
	private int $stingDeathTicks = 0;
	private int $wanderTicker = 0;

	private int $ageTicks = 0;
	private int $loveTicks = 0;
	private int $loveCooldown = 0;
	private bool $inLove = false;
	private int $heartParticleTicker = 0;
	private int $breedKissTicks = 0;

	private float $dirX = 0.0;
	private float $dirY = 0.0;
	private float $dirZ = 0.0;
	private float $currentSpeed = self::FLY_SPEED;
	private float $hoverTargetY;

	private bool $isPollinating = false;
	private bool $hasActiveTarget = false;
	private float $activeTargetY = 0.0;
	private int $groundCheckTicker = 0;
	private float $cachedGroundY = 0.0;
	private int $separationTicker = 0;
	private float $cachedSepX = 0.0;
	private float $cachedSepZ = 0.0;
	private int $followScanCooldown = 0;
	private ?Player $cachedFlowerHolder = null;
	private int $breedScanCooldown = 0;
	private ?self $cachedBreedMate = null;
	private int $babyFollowScanCooldown = 0;
	private ?self $cachedBabyLeader = null;

	private ?Vector3 $flowerTarget = null;
	private ?Vector3 $homeHive = null;
	private int $hiveEntryCooldown = 0;
	private int $blockValidateTicker = 0;
	private bool $cachedIsNight = false;
	private int $nightCheckTicker = 0;

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.5, 0.55);
	}

	protected function getInitialGravity() : float{
		return 0.04;
	}

	protected function getInitialDragMultiplier() : float{
		return 0.02;
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return 0;
	}

	public function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(10);
		parent::initEntity($nbt);

		$this->dirX = mt_rand(-1000, 1000) / 1000.0;
		$this->dirY = mt_rand(-50, 50) / 1000.0;
		$this->dirZ = mt_rand(-1000, 1000) / 1000.0;
		$this->hoverTargetY = self::FLY_HOVER_MIN_Y + (mt_rand(0, 3000) / 1000.0) * (self::FLY_HOVER_MAX_Y - self::FLY_HOVER_MIN_Y);
		$this->activeTargetY = $this->location->y;
		$this->cachedGroundY = $this->location->y;
		$this->targetSearchCooldown = mt_rand(0, self::FLOWER_SEARCH_INTERVAL);
		$this->groundCheckTicker = mt_rand(0, self::GROUND_CHECK_INTERVAL);
		$this->separationTicker = mt_rand(0, self::SEPARATION_UPDATE_INTERVAL);
		$this->followScanCooldown = mt_rand(0, self::FOLLOW_SCAN_INTERVAL);
		$this->breedScanCooldown = mt_rand(0, self::BREED_SCAN_INTERVAL);
		$this->babyFollowScanCooldown = mt_rand(0, self::BABY_FOLLOW_SCAN_INTERVAL);
		$this->blockValidateTicker = mt_rand(0, 3);
		$this->nightCheckTicker = mt_rand(0, 19);
		$properties = $nbt->getCompoundTag(self::TAG_PROPERTIES);
		if($properties !== null && $properties->getTag(self::TAG_PROPERTY_HAS_NECTAR) !== null){
			$this->hasNectar = $properties->getByte(self::TAG_PROPERTY_HAS_NECTAR, 0) !== 0;
		}else{
			$this->hasNectar = $nbt->getByte(self::TAG_HAS_NECTAR, 0) !== 0;
		}
		$this->hasStung = $nbt->getByte(self::TAG_HAS_STUNG, 0) !== 0;
		$this->angerTicks = $nbt->getInt(self::TAG_ANGER_TIME, 0);
		$this->angry = $this->angerTicks > 0 && !$this->hasStung;
		$this->currentSpeed = $this->hasNectar ? self::FLY_SPEED_NECTAR : self::FLY_SPEED;
		$this->baby = $nbt->getByte(self::TAG_BABY, 0) !== 0;
		$this->ageTicks = $nbt->getInt(self::TAG_AGE, 0);
		$this->loveCooldown = $nbt->getInt(self::TAG_LOVE_COOLDOWN, 0);

		if($this->baby){
			$this->setScale(self::BABY_SCALE);
		}

		if(
			($nbt->getTag(self::TAG_HOME_X) instanceof IntTag) &&
			($nbt->getTag(self::TAG_HOME_Y) instanceof IntTag) &&
			($nbt->getTag(self::TAG_HOME_Z) instanceof IntTag)
		){
			$this->homeHive = new Vector3(
				$nbt->getInt(self::TAG_HOME_X),
				$nbt->getInt(self::TAG_HOME_Y),
				$nbt->getInt(self::TAG_HOME_Z)
			);
			if(!$this->hasNectar){
				$this->hiveEntryCooldown = 200;
			}
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_HAS_NECTAR, $this->hasNectar ? 1 : 0);
		$nbt->setByte(self::TAG_HAS_STUNG, $this->hasStung ? 1 : 0);
		$nbt->setInt(self::TAG_ANGER_TIME, $this->angerTicks);
		$nbt->setByte(self::TAG_BABY, $this->baby ? 1 : 0);
		$nbt->setInt(self::TAG_AGE, $this->ageTicks);
		$nbt->setInt(self::TAG_LOVE_COOLDOWN, $this->loveCooldown);
		$nbt->setTag(
			self::TAG_PROPERTIES,
			CompoundTag::create()->setByte(self::TAG_PROPERTY_HAS_NECTAR, $this->hasNectar ? 1 : 0)
		);

		if($this->homeHive !== null){
			$nbt->setInt(self::TAG_HOME_X, (int) $this->homeHive->x);
			$nbt->setInt(self::TAG_HOME_Y, (int) $this->homeHive->y);
			$nbt->setInt(self::TAG_HOME_Z, (int) $this->homeHive->z);
		}

		return $nbt;
	}

	public function getName() : string{
		return "Bee";
	}

	public function isBaby() : bool{
		return $this->baby;
	}

	public function setBaby(bool $baby = true) : void{
		$this->baby = $baby;
		$this->setScale($baby ? self::BABY_SCALE : 1.0);
		$this->networkPropertiesDirty = true;
	}

	public function hasNectar() : bool{
		return $this->hasNectar;
	}

	public function isInLove() : bool{
		return $this->inLove;
	}

	private static function isBreedingFlower(Item $item) : bool{
		$block = $item->getBlock();
		return $block instanceof Flower || $block instanceof DoublePlant;
	}

	private static function isPlayerHoldingBreedingFlower(Player $player) : bool{
		if(!$player->isConnected() || !$player->isAlive() || $player->isSpectator()){
			return false;
		}
		try{
			$item = $player->getInventory()->getItemInHand();
		}catch(\Error){
			return false;
		}
		return self::isBreedingFlower($item);
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		$item = $player->getInventory()->getItemInHand();

		if($item instanceof SpawnEgg){
			$baby = new self(Location::fromObject($this->location->add(0, 0.5, 0), $this->getWorld(), mt_rand(0, 360), 0));
			$baby->setBaby();
			$baby->spawnToAll();
			if(!$player->isCreative()){
				$item->pop();
				$player->getInventory()->setItemInHand($item);
			}
			return true;
		}

		if(!$this->baby && !$this->inLove && $this->loveCooldown <= 0 && self::isBreedingFlower($item)){
			$this->inLove = true;
			$this->loveTicks = self::LOVE_MODE_DURATION;
			$this->heartParticleTicker = 0;
			$this->networkPropertiesDirty = true;
			$this->getWorld()->addParticle(
				$this->location->add(0, $this->getSize()->getHeight() + 0.2, 0),
				new HeartParticle()
			);
			if(!$player->isCreative()){
				$item->pop();
				$player->getInventory()->setItemInHand($item);
			}
			return true;
		}

		if($this->baby && self::isBreedingFlower($item)){
			$this->ageTicks = min($this->ageTicks + (int) (self::BABY_GROW_TICKS * 0.1), self::BABY_GROW_TICKS);
			if($this->ageTicks >= self::BABY_GROW_TICKS){
				$this->setBaby(false);
				$this->ageTicks = 0;
			}
			$this->getWorld()->broadcastPacketToViewers(
				$this->location,
				ActorEventPacket::create($this->getId(), ActorEvent::BABY_ANIMAL_FEED, 0)
			);
			if(!$player->isCreative()){
				$item->pop();
				$player->getInventory()->setItemInHand($item);
			}
			return true;
		}

		return parent::onInteract($player, $clickPos);
	}

	private function randomizeDirection() : void{
		if($this->homeHive !== null){
			$dx = $this->homeHive->x + 0.5 - $this->location->x;
			$dz = $this->homeHive->z + 0.5 - $this->location->z;
			$distSq = $dx * $dx + $dz * $dz;
			if($distSq > self::MAX_WANDER_DISTANCE_SQ * 0.6){
				$dy = mt_rand(-50, 50) / 1000.0;
				$len = $dx * $dx + $dy * $dy + $dz * $dz;
				if($len > 0.0001){
					$inv = 1.0 / sqrt($len);
					$this->dirX = $dx * $inv;
					$this->dirY = $dy * $inv;
					$this->dirZ = $dz * $inv;
				}
				return;
			}
		}
		$this->dirX = mt_rand(-1000, 1000) / 1000.0;
		$this->dirY = mt_rand(-50, 50) / 1000.0;
		$this->dirZ = mt_rand(-1000, 1000) / 1000.0;
	}

	private function blendDirection(float $dx, float $dy, float $dz) : void{
		$len = $dx * $dx + $dy * $dy + $dz * $dz;
		if($len <= 0.0001){
			return;
		}
		$inv = 1.0 / sqrt($len);
		$tx = $dx * $inv;
		$ty = $dy * $inv;
		$tz = $dz * $inv;
		$ddx = $this->dirX + ($tx - $this->dirX) * self::FLY_DIRECTION_BLEND;
		$ddy = $this->dirY + ($ty - $this->dirY) * self::FLY_DIRECTION_BLEND;
		$ddz = $this->dirZ + ($tz - $this->dirZ) * self::FLY_DIRECTION_BLEND;
		$nLen = $ddx * $ddx + $ddy * $ddy + $ddz * $ddz;
		if($nLen > 0.0001){
			$nInv = 1.0 / sqrt($nLen);
			$ddx *= $nInv;
			$ddy *= $nInv;
			$ddz *= $nInv;
		}
		$this->dirX = $ddx;
		$this->dirY = $ddy;
		$this->dirZ = $ddz;
	}

	private function isPollinableFlower(Vector3 $pos) : bool{
		$block = $this->getWorld()->getBlockAt((int) $pos->x, (int) $pos->y, (int) $pos->z);
		return $block instanceof Flower || ($block instanceof DoublePlant && !$block->isTop());
	}

	private function findNearestFlower() : ?Vector3{
		$world = $this->getWorld();
		$bx = (int) $this->location->x;
		$by = (int) $this->location->y;
		$bz = (int) $this->location->z;
		$locX = $this->location->x;
		$locY = $this->location->y;
		$locZ = $this->location->z;

		$best = null;
		$bestDist = PHP_INT_MAX;

		$scanYLow = max($world->getMinY(), $by - 2);
		$scanYHigh = min($world->getMaxY() - 1, $by + 2);
		for($scanY = $scanYLow; $scanY <= $scanYHigh; ++$scanY){
			for($x = $bx - self::FLOWER_SEARCH_RADIUS; $x <= $bx + self::FLOWER_SEARCH_RADIUS; ++$x){
				for($z = $bz - self::FLOWER_SEARCH_RADIUS; $z <= $bz + self::FLOWER_SEARCH_RADIUS; ++$z){
					$block = $world->getBlockAt($x, $scanY, $z);
					if(!($block instanceof Flower) && !($block instanceof DoublePlant && !$block->isTop())){
						continue;
					}
					if(self::isFlowerOccupied($x, $scanY, $z)){
						continue;
					}
					$dx = $locX - ($x + 0.5);
					$dy = $locY - ($scanY + 0.5);
					$dz = $locZ - ($z + 0.5);
					$d = $dx * $dx + $dy * $dy + $dz * $dz;
					if($d < $bestDist){
						$bestDist = $d;
						$best = new Vector3($x, $scanY, $z);
					}
				}
			}
		}
		if($best !== null){
			return $best;
		}

		$yMin = max($world->getMinY(), $by - self::SEARCH_VERTICAL_RANGE);
		$yMax = min($world->getMaxY() - 1, $by + self::SEARCH_VERTICAL_RANGE);
		for($i = 0; $i < self::FLOWER_SEARCH_SAMPLES; ++$i){
			$x = $bx + mt_rand(-self::FLOWER_SEARCH_RADIUS, self::FLOWER_SEARCH_RADIUS);
			$z = $bz + mt_rand(-self::FLOWER_SEARCH_RADIUS, self::FLOWER_SEARCH_RADIUS);
			$y = mt_rand($yMin, $yMax);

			$block = $world->getBlockAt($x, $y, $z);
			if(!($block instanceof Flower) && !($block instanceof DoublePlant && !$block->isTop())){
				continue;
			}
			if(self::isFlowerOccupied($x, $y, $z)){
				continue;
			}
			$dx = $locX - ($x + 0.5);
			$dy = $locY - ($y + 0.5);
			$dz = $locZ - ($z + 0.5);
			$d = $dx * $dx + $dy * $dy + $dz * $dz;
			if($d < $bestDist){
				$bestDist = $d;
				$best = new Vector3($x, $y, $z);
			}
		}
		return $best;
	}

	private function isHiveBlock(Vector3 $pos) : bool{
		$block = $this->getWorld()->getBlockAt((int) $pos->x, (int) $pos->y, (int) $pos->z);
		return $block instanceof Beehive || $block instanceof BeeNest;
	}

	private function findNearestHive() : ?Vector3{
		$world = $this->getWorld();
		$bx = (int) $this->location->x;
		$by = (int) $this->location->y;
		$bz = (int) $this->location->z;
		$yMin = max($world->getMinY(), $by - self::SEARCH_VERTICAL_RANGE);
		$yMax = min($world->getMaxY() - 1, $by + self::SEARCH_VERTICAL_RANGE);

		$best = null;
		$bestDist = PHP_INT_MAX;

		// Small precise scan (6 block radius, ±3 Y)
		$nearRadius = 6;
		$scanYLow = max($yMin, $by - 3);
		$scanYHigh = min($yMax, $by + 3);
		for($scanY = $scanYLow; $scanY <= $scanYHigh; ++$scanY){
			for($x = $bx - $nearRadius; $x <= $bx + $nearRadius; ++$x){
				for($z = $bz - $nearRadius; $z <= $bz + $nearRadius; ++$z){
					$block = $world->getBlockAt($x, $scanY, $z);
					if(!($block instanceof Beehive) && !($block instanceof BeeNest)){
						continue;
					}
					$tile = $world->getTile(new Vector3($x, $scanY, $z));
					if($tile instanceof TileBeehive && $tile->isFull()){
						continue;
					}
					$dx = $this->location->x - ($x + 0.5);
					$dy = $this->location->y - ($scanY + 0.5);
					$dz = $this->location->z - ($z + 0.5);
					$d = $dx * $dx + $dy * $dy + $dz * $dz;
					if($d < $bestDist){
						$bestDist = $d;
						$best = new Vector3($x, $scanY, $z);
					}
				}
			}
		}
		if($best !== null){
			return $best;
		}

		// Random sample for wider area
		for($i = 0; $i < self::HIVE_SEARCH_SAMPLES; ++$i){
			$x = $bx + mt_rand(-self::HIVE_SEARCH_RADIUS, self::HIVE_SEARCH_RADIUS);
			$z = $bz + mt_rand(-self::HIVE_SEARCH_RADIUS, self::HIVE_SEARCH_RADIUS);
			$y = mt_rand($yMin, $yMax);

			$block = $world->getBlockAt($x, $y, $z);
			if(!($block instanceof Beehive) && !($block instanceof BeeNest)){
				continue;
			}
			$tile = $world->getTile(new Vector3($x, $y, $z));
			if($tile instanceof TileBeehive && $tile->isFull()){
				continue;
			}
			$dx = $this->location->x - ($x + 0.5);
			$dy = $this->location->y - ($y + 0.5);
			$dz = $this->location->z - ($z + 0.5);
			$d = $dx * $dx + $dy * $dy + $dz * $dz;
			if($d < $bestDist){
				$bestDist = $d;
				$best = new Vector3($x, $y, $z);
			}
		}
		return $best;
	}

	private function tryPollinate(int $tickDiff) : void{
		if($this->flowerTarget === null){
			$this->isPollinating = false;
			return;
		}

		if(!self::claimFlower((int) $this->flowerTarget->x, (int) $this->flowerTarget->y, (int) $this->flowerTarget->z, $this->getId())){
			$this->flowerTarget = null;
			$this->pollinateTicks = 0;
			$this->isPollinating = false;
			return;
		}

		$hx = $this->flowerTarget->x + 0.5;
		$hy = $this->flowerTarget->y + 0.8;
		$hz = $this->flowerTarget->z + 0.5;
		$dx = $this->location->x - $hx;
		$dy = $this->location->y - $hy;
		$dz = $this->location->z - $hz;
		$distSq = $dx * $dx + $dy * $dy + $dz * $dz;

		if($distSq > self::FLOWER_REACH_SQ){
			$this->hasActiveTarget = true;
			$this->activeTargetY = $hy;
			$this->blendDirection(-$dx, -$dy, -$dz);
			$this->currentSpeed = self::FLY_SPEED;
			$this->pollinateTicks = 0;
			$this->isPollinating = false;
			return;
		}

		$this->isPollinating = true;
		$this->hasActiveTarget = false;
		$this->currentSpeed = 0.0;
		$this->motion = new Vector3(-$dx * 0.2, -$dy * 0.2, -$dz * 0.2);

		$this->pollinateTicks += $tickDiff;
		if($this->pollinateTicks >= self::POLLINATE_TICKS_REQUIRED){
			$this->setHasNectar(true);
			$this->pollinateTicks = 0;
			self::releaseFlower((int) $this->flowerTarget->x, (int) $this->flowerTarget->y, (int) $this->flowerTarget->z, $this->getId());
			$this->flowerTarget = null;
			$this->isPollinating = false;
			$this->targetSearchCooldown = 0;
			$this->currentSpeed = self::FLY_SPEED_NECTAR;
			$this->hoverTargetY = self::FLY_HOVER_MIN_Y + (mt_rand(0, 1500) / 1000.0) * (self::FLY_HOVER_MAX_Y - self::FLY_HOVER_MIN_Y);
			$this->activeTargetY = $this->location->y;
			$this->hasActiveTarget = false;
			$this->randomizeDirection();
		}
	}

	private function setHasNectar(bool $nectar) : void{
		if($this->hasNectar === $nectar){
			return;
		}
		$this->hasNectar = $nectar;
		$this->entityPropertiesDirty = true;
		$this->networkPropertiesDirty = true;
	}

	private function tryDepositNectar() : void{
		if($this->homeHive === null){
			return;
		}

		$cx = $this->homeHive->x + 0.5;
		$cy = $this->homeHive->y + 0.5;
		$cz = $this->homeHive->z + 0.5;
		$this->hasActiveTarget = true;
		$this->activeTargetY = $cy;
		$this->blendDirection($cx - $this->location->x, $cy - $this->location->y, $cz - $this->location->z);
		$this->currentSpeed = self::FLY_SPEED_NECTAR;

		$dx = $this->location->x - $cx;
		$dy = $this->location->y - $cy;
		$dz = $this->location->z - $cz;
		if($dx * $dx + $dy * $dy + $dz * $dz > self::HIVE_REACH_SQ){
			return;
		}

		$world = $this->getWorld();
		$tile = $world->getTile($this->homeHive);
		if($tile instanceof TileBeehive && !$tile->isFull()){
			$tile->addBee($this->saveNBT(), $this->hasNectar);
			$world->addSound(new Vector3($cx, $cy, $cz), new BeehiveEnterSound());
			$this->flagForDespawn();
			return;
		}

		$this->homeHive = null;
		$this->randomizeDirection();
		$this->targetSearchCooldown = self::HIVE_SEARCH_INTERVAL;
	}

	private function isNightTime() : bool{
		$time = $this->getWorld()->getTimeOfDay();
		return $time >= World::TIME_SUNSET && $time < World::TIME_SUNRISE;
	}

	private function tickGathering(int $tickDiff) : void{
		if($this->targetSearchCooldown > 0){
			$this->targetSearchCooldown = max(0, $this->targetSearchCooldown - $tickDiff);
		}
		if($this->hiveEntryCooldown > 0){
			$this->hiveEntryCooldown = max(0, $this->hiveEntryCooldown - $tickDiff);
		}

		// Throttle night check (every 20 ticks)
		$this->nightCheckTicker += $tickDiff;
		if($this->nightCheckTicker >= 20){
			$this->nightCheckTicker = 0;
			$this->cachedIsNight = $this->isNightTime();
		}

		// Throttle block validation (every 4 ticks)
		$this->blockValidateTicker += $tickDiff;
		$shouldValidate = $this->blockValidateTicker >= 4;
		if($shouldValidate){
			$this->blockValidateTicker = 0;
		}

		$shouldSearchHive = $this->hasNectar || $this->cachedIsNight;

		if($shouldSearchHive && $this->hiveEntryCooldown <= 0){
			if($this->cachedIsNight && $this->flowerTarget !== null){
				self::releaseFlower((int) $this->flowerTarget->x, (int) $this->flowerTarget->y, (int) $this->flowerTarget->z, $this->getId());
				$this->flowerTarget = null;
				$this->pollinateTicks = 0;
				$this->isPollinating = false;
			}
			$this->currentSpeed = self::FLY_SPEED_NECTAR;
			if($this->homeHive === null || ($shouldValidate && !$this->isHiveBlock($this->homeHive))){
				if($this->homeHive !== null && $shouldValidate){
					$this->homeHive = null;
				}
				if($this->homeHive === null && $this->targetSearchCooldown === 0){
					$found = $this->findNearestHive();
					$this->homeHive = $found;
					$this->targetSearchCooldown = $found !== null ? self::HIVE_SEARCH_INTERVAL : (int) (self::HIVE_SEARCH_INTERVAL * 0.25);
				}
			}elseif($shouldValidate && $this->homeHive !== null){
				$tile = $this->getWorld()->getTile($this->homeHive);
				if($tile instanceof TileBeehive && $tile->isFull()){
					$this->homeHive = null;
					$this->targetSearchCooldown = 0;
				}
			}
			$this->tryDepositNectar();
			return;
		}

		$this->currentSpeed = self::FLY_SPEED;
		if($this->flowerTarget !== null && $shouldValidate && !$this->isPollinableFlower($this->flowerTarget)){
			self::releaseFlower((int) $this->flowerTarget->x, (int) $this->flowerTarget->y, (int) $this->flowerTarget->z, $this->getId());
			$this->flowerTarget = null;
			$this->isPollinating = false;
		}
		if($this->flowerTarget === null){
			if($this->targetSearchCooldown === 0){
				$found = $this->findNearestFlower();
				$this->flowerTarget = $found;
				$this->targetSearchCooldown = $found !== null ? self::FLOWER_SEARCH_INTERVAL : (int) (self::FLOWER_SEARCH_INTERVAL * 0.4);
			}
		}
		$this->tryPollinate($tickDiff);
	}

	public function setAngry(Entity $target) : void{
		if($this->hasStung || !$this->isAlive()){
			return;
		}
		if($target instanceof Player && $target->isCreative()){
			return;
		}
		$this->setTargetEntity($target);
		$this->angerTicks = self::ANGER_DURATION_TICKS;
		$this->angry = true;
		$this->networkPropertiesDirty = true;
	}

	public static function alertNearbyBees(Entity $target, Vector3 $pos, World $world) : void{
		foreach($world->getNearbyEntities(
			$target->getBoundingBox()->expandedCopy(self::NEARBY_BEE_ALERT_RADIUS, self::NEARBY_BEE_ALERT_RADIUS, self::NEARBY_BEE_ALERT_RADIUS),
			$target
		) as $entity){
			if($entity instanceof self){
				$entity->setAngry($target);
			}
		}
	}

	private function clearAnger() : void{
		$this->angerTicks = 0;
		$this->angry = false;
		$this->setTargetEntity(null);
		$this->networkPropertiesDirty = true;
	}

	private function performSting(Living $target) : void{
		$ev = new EntityDamageByEntityEvent(
			$this,
			$target,
			EntityDamageEvent::CAUSE_ENTITY_ATTACK,
			self::STING_DAMAGE
		);
		$target->attack($ev);
		if($ev->isCancelled()){
			return;
		}

		$this->broadcastAnimation(new BeeStingAnimation($this));
		$this->broadcastSound(new BeeStingSound());

		$target->getEffects()->add(new EffectInstance(VanillaEffects::POISON(), self::STING_POISON_DURATION, 0, true));

		$this->hasStung = true;
		$this->setHasNectar(false);
		$this->networkPropertiesDirty = true;
		$this->pollinateTicks = 0;
		$this->flowerTarget = null;
		$this->stingDeathTicks = self::STING_DEATH_DELAY;
		$this->clearAnger();
		$this->randomizeDirection();
	}

	private function tickCombat(int $tickDiff) : bool{
		if($this->hasStung){
			$this->stingDeathTicks = max(0, $this->stingDeathTicks - $tickDiff);
			if($this->stingDeathTicks === 0){
				$this->kill();
				return true;
			}
			return false;
		}

		if($this->angerTicks <= 0){
			if($this->angry){
				$this->clearAnger();
			}
			return false;
		}

		$this->angerTicks = max(0, $this->angerTicks - $tickDiff);

		$target = $this->getTargetEntity();
		if(!($target instanceof Living) || !$target->isAlive() || $target->getWorld() !== $this->getWorld()){
			$this->clearAnger();
			return true;
		}

		if($target instanceof Player && $target->isCreative()){
			$this->clearAnger();
			return true;
		}

		$this->currentSpeed = self::FLY_SPEED_ANGRY;
		$tY = $target->location->y + $target->getSize()->getHeight() * 0.5;
		$dx = $target->location->x - $this->location->x;
		$dy = $tY - $this->location->y;
		$dz = $target->location->z - $this->location->z;
		$distSq = $dx * $dx + $dy * $dy + $dz * $dz;
		$this->hasActiveTarget = true;
		$this->activeTargetY = $tY;
		$this->blendDirection($dx, $dy, $dz);
		if($distSq <= self::STING_RANGE_SQ){
			$this->performSting($target);
		}

		return true;
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if($source->isCancelled() || !$this->isAlive() || $this->hasStung){
			return;
		}

		if($source instanceof EntityDamageByEntityEvent){
			$damager = $source->getDamager();
			if($damager !== null && $damager !== $this && !$damager->closed){
				$this->setAngry($damager);
				self::alertNearbyBees($damager, $this->location, $this->getWorld());
			}
		}
	}

	private function getGroundHeight() : float{
		$world = $this->getWorld();
		$x = (int) $this->location->x;
		$z = (int) $this->location->z;
		$y = (int) $this->location->y;
		for($checkY = $y; $checkY >= max($world->getMinY(), $y - 10); --$checkY){
			$block = $world->getBlockAt($x, $checkY, $z);
			if($block->isSolid()){
				return (float) ($checkY + 1);
			}
		}
		return (float) $world->getMinY();
	}

	private function applyFlight(int $tickDiff) : void{
		$world = $this->getWorld();
		$minY = $world->getMinY() + 2;
		$maxY = $world->getMaxY() - 3;
		$locY = $this->location->y;

		if($this->isCollidedHorizontally){
			$ndx = -$this->dirX + (mt_rand(-200, 200) / 1000);
			$ndz = -$this->dirZ + (mt_rand(-200, 200) / 1000);
			$len = $ndx * $ndx + $ndz * $ndz;
			if($len > 0.0001){
				$inv = 1.0 / sqrt($len);
				$ndx *= $inv;
				$ndz *= $inv;
			}
			$this->dirX = $ndx;
			$this->dirY = 0.0;
			$this->dirZ = $ndz;
			$this->wanderTicker = 0;
		}

		if($this->homeHive !== null && !$this->angry){
			$dx = $this->location->x - ($this->homeHive->x + 0.5);
			$dz = $this->location->z - ($this->homeHive->z + 0.5);
			if($dx * $dx + $dz * $dz > self::MAX_WANDER_DISTANCE_SQ){
				$this->hasActiveTarget = true;
				$this->activeTargetY = $locY;
				$this->blendDirection(-$dx, 0.0, -$dz);
			}
		}

		$this->groundCheckTicker += $tickDiff;
		if($this->groundCheckTicker >= self::GROUND_CHECK_INTERVAL){
			$this->groundCheckTicker = 0;
			$this->cachedGroundY = $this->getGroundHeight();
		}
		$groundY = $this->cachedGroundY;
		$yCorrection = 0.0;
		$cachedTarget = $this->getTargetEntity();
		$isAttacking = $this->angry && !$this->hasStung && $cachedTarget !== null;
		$hasTask = $this->hasActiveTarget;
		$maxYSpeed = $isAttacking ? self::FLY_MAX_Y_SPEED_ANGRY : self::FLY_MAX_Y_SPEED;
		if($hasTask && !$isAttacking){
			$maxYSpeed = 0.09;
		}

		$hasPreciseVerticalTask = !$isAttacking && $hasTask && ($this->flowerTarget !== null || ($this->hasNectar && $this->homeHive !== null));
		if($hasPreciseVerticalTask){
			$yDiff = $this->activeTargetY - $locY;
			if(abs($yDiff) > 0.05){
				$yCorrection = $yDiff > 0
					? min($yDiff * 0.12, 0.18)
					: max($yDiff * 0.12, -0.18);
			}
		}elseif($hasTask && !$isAttacking){
			$yDiff = $this->activeTargetY - $locY;
			if(abs($yDiff) > 0.2){
				$yCorrection = $yDiff > 0
					? min($yDiff * 0.10, 0.14)
					: max($yDiff * 0.10, -0.14);
			}else{
				$yDiff = $groundY + $this->hoverTargetY - $locY;
				if($locY > $maxY){
					$yCorrection = -0.3;
				}elseif($locY < $minY){
					$yCorrection = 0.3;
				}elseif(abs($yDiff) > 0.25){
					$yCorrection = $yDiff > 0 ? min($yDiff * 0.08, 0.12) : max($yDiff * 0.12, -0.15);
				}
			}
		}elseif($isAttacking){
			if($cachedTarget !== null){
				$yDiff = ($cachedTarget->location->y + $cachedTarget->getSize()->getHeight() * 0.5) - $locY;
				if(abs($yDiff) > 0.3){
					$yCorrection = $yDiff > 0 ? min($yDiff * 0.13, 0.22) : max($yDiff * 0.13, -0.22);
				}
			}
		}else{
			$yDiff = $groundY + $this->hoverTargetY - $locY;
			if($locY > $maxY){
				$yCorrection = -0.3;
			}elseif($locY < $minY){
				$yCorrection = 0.3;
			}elseif(abs($yDiff) > 0.25){
				$yCorrection = $yDiff > 0 ? min($yDiff * 0.08, 0.12) : max($yDiff * 0.12, -0.15);
			}
		}

		$this->wanderTicker += $tickDiff;
		if($this->wanderTicker >= self::FLY_WANDER_INTERVAL){
			$this->wanderTicker = 0;
			if($this->flowerTarget === null && !$this->hasNectar && !$this->angry && $cachedTarget === null){
				$this->randomizeDirection();
				$this->hoverTargetY = self::FLY_HOVER_MIN_Y + (mt_rand(0, 3000) / 1000.0) * (self::FLY_HOVER_MAX_Y - self::FLY_HOVER_MIN_Y);
			}
		}

		if($this->isPollinating){
			// tryPollinate already set motion directly; skip all motion calc
		}else{
			$flyX = $this->dirX * $this->currentSpeed;
			$flyZ = $this->dirZ * $this->currentSpeed;
			$dirY = ($isAttacking || $hasTask) ? $this->dirY * $this->currentSpeed : 0.0;
			$flyY = $yCorrection + self::FLY_GRAVITY_OFFSET + (mt_rand(-100, 100) * 0.0001) * self::FLY_HOVER_Y_VARIANCE + $dirY;

			if($isAttacking){
				$this->separationTicker += $tickDiff;
				if($this->separationTicker >= self::SEPARATION_UPDATE_INTERVAL){
					$this->separationTicker = 0;
					$sepX = 0.0;
					$sepZ = 0.0;
					$nearbyCount = 0;
					$sepRadiusSq = self::SEPARATION_RADIUS * self::SEPARATION_RADIUS;
					foreach($world->getNearbyEntities(
						$this->getBoundingBox()->expandedCopy(self::SEPARATION_RADIUS, self::SEPARATION_RADIUS, self::SEPARATION_RADIUS),
						$this
					) as $nearby){
						if(!($nearby instanceof self)){
							continue;
						}
						$ndx = $this->location->x - $nearby->location->x;
						$ndz = $this->location->z - $nearby->location->z;
						$nDistSq = $ndx * $ndx + $ndz * $ndz;
						if($nDistSq < 0.001 || $nDistSq > $sepRadiusSq){
							continue;
						}
						$nDist = sqrt($nDistSq);
						$factor = (1.0 - $nDist / self::SEPARATION_RADIUS) * self::SEPARATION_STRENGTH;
						$sepX += ($ndx / $nDist) * $factor;
						$sepZ += ($ndz / $nDist) * $factor;
						if(++$nearbyCount >= self::SEPARATION_MAX_NEIGHBORS){
							break;
						}
					}
					$this->cachedSepX = $sepX;
					$this->cachedSepZ = $sepZ;
				}
				$flyX += $this->cachedSepX;
				$flyZ += $this->cachedSepZ;
			}else{
				$this->cachedSepX = 0.0;
				$this->cachedSepZ = 0.0;
			}

			// Blend motion - compute final values in locals, single Vector3 at end
			$mx = $this->motion->x * 0.2 + $flyX * 0.8;
			$my = $this->motion->y * 0.5 + $flyY * 0.5;
			$mz = $this->motion->z * 0.2 + $flyZ * 0.8;

			if($my > $maxYSpeed){
				$my = $maxYSpeed;
			}elseif($my < -$maxYSpeed){
				$my = -$maxYSpeed;
			}

			$hSq = $mx * $mx + $mz * $mz;
			$maxSpd = $isAttacking
				? self::FLY_SPEED_ANGRY
				: max(self::FLY_MAX_SPEED, $this->currentSpeed + 0.02);
			$maxSpdSq = $maxSpd * $maxSpd;
			if($hSq > $maxSpdSq){
				$scale = $maxSpd / sqrt($hSq);
				$mx *= $scale;
				$mz *= $scale;
			}

			$this->motion = new Vector3($mx, $my, $mz);
		}

		$mx = $this->motion->x;
		$mz = $this->motion->z;
		$hSpeed = sqrt($mx * $mx + $mz * $mz);
		$yaw = -atan2($mx, $mz) * 180.0 / M_PI;
		$pitch = -atan2($hSpeed, $this->motion->y) * 180.0 / M_PI;

		if(!$this->angry && !$this->hasStung && $this->cachedFlowerHolder !== null){
			$lookDx = $this->cachedFlowerHolder->location->x - $this->location->x;
			$lookDz = $this->cachedFlowerHolder->location->z - $this->location->z;
			if(($lookDx * $lookDx + $lookDz * $lookDz) > 0.0001){
				$yaw = -atan2($lookDx, $lookDz) * 180.0 / M_PI;
			}
		}

		$this->setRotation($yaw, $pitch);
	}

	private function findNearbyFlowerHolder() : ?Player{
		$world = $this->getWorld();
		$best = null;
		$bestDist = self::FOLLOW_FLOWER_RANGE * self::FOLLOW_FLOWER_RANGE;
		$sx = $this->location->x;
		$sy = $this->location->y;
		$sz = $this->location->z;

		foreach($world->getPlayers() as $player){
			if(!self::isPlayerHoldingBreedingFlower($player)){
				continue;
			}
			$dx = $sx - $player->location->x;
			$dy = $sy - $player->location->y;
			$dz = $sz - $player->location->z;
			$d = $dx * $dx + $dy * $dy + $dz * $dz;
			if($d < $bestDist){
				$bestDist = $d;
				$best = $player;
			}
		}
		return $best;
	}

	private function tickFollowFlower(int $tickDiff) : bool{
		if($this->angry || $this->hasStung){
			return false;
		}
		$this->followScanCooldown = max(0, $this->followScanCooldown - $tickDiff);
		if($this->cachedFlowerHolder !== null){
			$h = $this->cachedFlowerHolder;
			if(
				!$h->isConnected() ||
				!$h->isAlive() ||
				$h->isSpectator() ||
				$h->getWorld() !== $this->getWorld() ||
				!self::isPlayerHoldingBreedingFlower($h)
			){
				$this->cachedFlowerHolder = null;
			}else{
				$hdx = $this->location->x - $h->location->x;
				$hdy = $this->location->y - $h->location->y;
				$hdz = $this->location->z - $h->location->z;
				if(($hdx * $hdx + $hdy * $hdy + $hdz * $hdz) > self::FOLLOW_FLOWER_RANGE * self::FOLLOW_FLOWER_RANGE){
					$this->cachedFlowerHolder = null;
				}
			}
		}
		if($this->followScanCooldown === 0){
			$this->followScanCooldown = self::FOLLOW_SCAN_INTERVAL;
			if($this->cachedFlowerHolder === null){
				$this->cachedFlowerHolder = $this->findNearbyFlowerHolder();
			}
		}
		$holder = $this->cachedFlowerHolder;
		if($holder === null){
			return false;
		}

		$hcx = $holder->location->x;
		$hcy = $holder->location->y + $holder->getSize()->getHeight() * 0.6;
		$hcz = $holder->location->z;
		$dx = $this->location->x - $hcx;
		$dz = $this->location->z - $hcz;
		$distSq = $dx * $dx + $dz * $dz;
		if($distSq < 0.0001){
			$dx = ($this->getId() % 2 === 0) ? 1.0 : -1.0;
			$dz = (($this->getId() / 2) % 2 === 0) ? 1.0 : -1.0;
			$distSq = $dx * $dx + $dz * $dz;
		}
		$dist = sqrt($distSq);
		$nx = $dx / $dist;
		$nz = $dz / $dist;

		if($distSq <= self::FOLLOW_MIN_DISTANCE_SQ){
			$etx = $hcx + $nx * (self::FOLLOW_MIN_DISTANCE + 0.5);
			$etz = $hcz + $nz * (self::FOLLOW_MIN_DISTANCE + 0.5);
			$this->blendDirection($etx - $this->location->x, $hcy - $this->location->y, $etz - $this->location->z);
			$this->currentSpeed = self::FOLLOW_SPEED * 0.75;
			return true;
		}

		if($distSq <= self::FOLLOW_HOLD_DISTANCE_SQ){
			$this->hasActiveTarget = true;
			$this->activeTargetY = $hcy;
			$this->currentSpeed = 0.0;
			$this->motion = new Vector3(
				$this->motion->x * 0.6,
				$this->motion->y,
				$this->motion->z * 0.6
			);
			return true;
		}

		$ftx = $hcx + $nx * self::FOLLOW_TARGET_OFFSET;
		$ftz = $hcz + $nz * self::FOLLOW_TARGET_OFFSET;
		$this->blendDirection($ftx - $this->location->x, $hcy - $this->location->y, $ftz - $this->location->z);
		$this->currentSpeed = self::FOLLOW_SPEED;
		return true;
	}

	private function tickBreeding(int $tickDiff) : void{
		if($this->baby || !$this->inLove){
			return;
		}
		$this->loveTicks = max(0, $this->loveTicks - $tickDiff);
		if($this->loveTicks <= 0){
			$this->inLove = false;
			$this->breedKissTicks = 0;
			$this->networkPropertiesDirty = true;
			return;
		}

		$this->heartParticleTicker += $tickDiff;
		if($this->heartParticleTicker >= self::HEART_PARTICLE_INTERVAL){
			$this->heartParticleTicker = 0;
			$this->getWorld()->addParticle(
				new Vector3(
					$this->location->x + (mt_rand(-30, 30) / 100.0),
					$this->location->y + $this->getSize()->getHeight() + 0.2,
					$this->location->z + (mt_rand(-30, 30) / 100.0)
				),
				new HeartParticle()
			);
		}

		$world = $this->getWorld();
		$this->breedScanCooldown = max(0, $this->breedScanCooldown - $tickDiff);
		if(
			$this->cachedBreedMate !== null && (
				!$this->cachedBreedMate->isAlive() ||
				$this->cachedBreedMate->closed ||
				$this->cachedBreedMate->baby ||
				!$this->cachedBreedMate->inLove ||
				$this->cachedBreedMate->getWorld() !== $world
			)
		){
			$this->cachedBreedMate = null;
			$this->breedKissTicks = 0;
		}

		if($this->breedScanCooldown === 0){
			$this->breedScanCooldown = self::BREED_SCAN_INTERVAL;
			$nearest = null;
			$nearestDist = 64.0;
			$bsx = $this->location->x;
			$bsy = $this->location->y;
			$bsz = $this->location->z;
			foreach($world->getNearbyEntities(
				$this->getBoundingBox()->expandedCopy(8, 4, 8),
				$this
			) as $entity){
				if(!($entity instanceof self) || $entity->baby || !$entity->inLove || $entity->getId() <= $this->getId()){
					continue;
				}
				$bdx = $bsx - $entity->location->x;
				$bdy = $bsy - $entity->location->y;
				$bdz = $bsz - $entity->location->z;
				$d = $bdx * $bdx + $bdy * $bdy + $bdz * $bdz;
				if($d < $nearestDist){
					$nearestDist = $d;
					$nearest = $entity;
				}
			}
			$this->cachedBreedMate = $nearest;
		}

		$nearest = $this->cachedBreedMate;
		if($nearest === null){
			return;
		}
		$brdx = $this->location->x - $nearest->location->x;
		$brdy = $this->location->y - $nearest->location->y;
		$brdz = $this->location->z - $nearest->location->z;
		$nearestDist = $brdx * $brdx + $brdy * $brdy + $brdz * $brdz;

		$this->blendDirection($nearest->location->x - $this->location->x, $nearest->location->y - $this->location->y, $nearest->location->z - $this->location->z);
		$nearest->blendDirection($this->location->x - $nearest->location->x, $this->location->y - $nearest->location->y, $this->location->z - $nearest->location->z);
		$this->currentSpeed = self::FOLLOW_SPEED;
		$nearest->currentSpeed = self::FOLLOW_SPEED;

		if($nearestDist > self::BREED_RANGE_SQ){
			$this->breedKissTicks = 0;
			return;
		}

		$this->breedKissTicks += $tickDiff;
		$nearest->breedKissTicks = $this->breedKissTicks;

		$this->currentSpeed = 0.0;
		$nearest->currentSpeed = 0.0;
		$this->hasActiveTarget = true;
		$nearest->hasActiveTarget = true;
		$this->activeTargetY = ($this->location->y + $nearest->location->y) / 2;
		$nearest->activeTargetY = $this->activeTargetY;
		$this->motion = new Vector3($this->motion->x * 0.3, $this->motion->y, $this->motion->z * 0.3);
		$nearest->motion = new Vector3($nearest->motion->x * 0.3, $nearest->motion->y, $nearest->motion->z * 0.3);

		// Face each other
		$dx = $nearest->location->x - $this->location->x;
		$dz = $nearest->location->z - $this->location->z;
		if(($dx * $dx + $dz * $dz) > 0.001){
			$yawToMate = -atan2($dx, $dz) * 180.0 / M_PI;
			$this->setRotation($yawToMate, $this->location->pitch);
			$nearest->setRotation(-atan2(-$dx, -$dz) * 180.0 / M_PI, $nearest->location->pitch);
		}

		// Emit kiss hearts during the phase
		if($this->breedKissTicks % self::BREED_KISS_HEART_INTERVAL === 0){
			$kissX = ($this->location->x + $nearest->location->x) / 2;
			$kissY = ($this->location->y + $nearest->location->y) / 2;
			$kissZ = ($this->location->z + $nearest->location->z) / 2;
			for($i = 0; $i < 3; ++$i){
				$world->addParticle(
					new Vector3(
						$kissX + (mt_rand(-20, 20) / 100.0),
						$kissY + 0.3 + (mt_rand(0, 30) / 100.0),
						$kissZ + (mt_rand(-20, 20) / 100.0)
					),
					new HeartParticle()
				);
			}
		}

		if($this->breedKissTicks < self::BREED_KISS_TICKS){
			return;
		}

		// Kiss complete - spawn baby

		$midX = ($this->location->x + $nearest->location->x) / 2;
		$midY = ($this->location->y + $nearest->location->y) / 2;
		$midZ = ($this->location->z + $nearest->location->z) / 2;

		$babyBee = new self(Location::fromObject(new Vector3($midX, $midY + 0.5, $midZ), $world, mt_rand(0, 360), 0));
		$babyBee->setBaby();
		$babyBee->spawnToAll();

		$world->dropExperience(new Vector3($midX, $midY, $midZ), mt_rand(self::BREED_XP_MIN, self::BREED_XP_MAX));

		for($i = 0; $i < 7; ++$i){
			$world->addParticle(
				new Vector3(
					$midX + (mt_rand(-50, 50) / 100.0),
					$midY + 0.5 + (mt_rand(0, 50) / 100.0),
					$midZ + (mt_rand(-50, 50) / 100.0)
				),
				new HeartParticle()
			);
		}

		$this->inLove = false;
		$this->loveTicks = 0;
		$this->loveCooldown = self::BREED_COOLDOWN;
		$this->heartParticleTicker = 0;
		$this->cachedBreedMate = null;
		$this->breedKissTicks = 0;
		$this->networkPropertiesDirty = true;

		$nearest->inLove = false;
		$nearest->loveTicks = 0;
		$nearest->loveCooldown = self::BREED_COOLDOWN;
		$nearest->heartParticleTicker = 0;
		$nearest->cachedBreedMate = null;
		$nearest->breedKissTicks = 0;
		$nearest->networkPropertiesDirty = true;
	}

	private function tickBabyGrowth(int $tickDiff) : void{
		if(!$this->baby){
			return;
		}
		$this->ageTicks += $tickDiff;
		if($this->ageTicks >= self::BABY_GROW_TICKS){
			$this->setBaby(false);
			$this->ageTicks = 0;
		}
	}

	private function tickBabyFollow(int $tickDiff) : bool{
		if(!$this->baby || $this->angry || $this->hasStung){
			return false;
		}
		$world = $this->getWorld();
		$this->babyFollowScanCooldown = max(0, $this->babyFollowScanCooldown - $tickDiff);
		if(
			$this->cachedBabyLeader !== null && (
				!$this->cachedBabyLeader->isAlive() ||
				$this->cachedBabyLeader->closed ||
				$this->cachedBabyLeader->baby ||
				$this->cachedBabyLeader->getWorld() !== $world
			)
		){
			$this->cachedBabyLeader = null;
		}

		if($this->babyFollowScanCooldown === 0){
			$this->babyFollowScanCooldown = self::BABY_FOLLOW_SCAN_INTERVAL;
			$nearest = null;
			$nearestDist = 100.0;
			$bfsx = $this->location->x;
			$bfsy = $this->location->y;
			$bfsz = $this->location->z;
			foreach($world->getNearbyEntities(
				$this->getBoundingBox()->expandedCopy(10, 6, 10),
				$this
			) as $entity){
				if(!($entity instanceof self) || $entity->baby || !$entity->isAlive()){
					continue;
				}
				$bfdx = $bfsx - $entity->location->x;
				$bfdy = $bfsy - $entity->location->y;
				$bfdz = $bfsz - $entity->location->z;
				$d = $bfdx * $bfdx + $bfdy * $bfdy + $bfdz * $bfdz;
				if($d < $nearestDist){
					$nearestDist = $d;
					$nearest = $entity;
				}
			}
			$this->cachedBabyLeader = $nearest;
		}

		$nearest = $this->cachedBabyLeader;
		if($nearest === null){
			return false;
		}
		$bflx = $this->location->x - $nearest->location->x;
		$bfly = $this->location->y - $nearest->location->y;
		$bflz = $this->location->z - $nearest->location->z;
		$nearestDist = $bflx * $bflx + $bfly * $bfly + $bflz * $bflz;

		if($nearestDist > 4.0){
			$this->blendDirection(
				$nearest->location->x - $this->location->x,
				$nearest->location->y + 0.3 - $this->location->y,
				$nearest->location->z - $this->location->z
			);
			$this->currentSpeed = self::FOLLOW_SPEED;
			return true;
		}
		return false;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		if($this->closed){
			return false;
		}

		$hasUpdate = parent::entityBaseTick($tickDiff);

		if($this->isAlive()){
			if($this->loveCooldown > 0){
				$this->loveCooldown = max(0, $this->loveCooldown - $tickDiff);
			}

			$this->hasActiveTarget = false;
			$this->isPollinating = false;

			$this->tickBabyGrowth($tickDiff);
			$this->tickBreeding($tickDiff);

			$following = $this->tickFollowFlower($tickDiff);
			if(!$following && $this->baby){
				$following = $this->tickBabyFollow($tickDiff);
			}

			if(!$this->tickCombat($tickDiff) && !$this->hasStung && !$following){
				$this->tickGathering($tickDiff);
			}
			$this->applyFlight($tickDiff);
		}

		return $hasUpdate;
	}

	public function getDrops() : array{
		return [];
	}

	public function getXpDropAmount() : int{
		return $this->baby ? 0 : mt_rand(1, 3);
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::BEE_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::BABY, $this->baby);
		$properties->setGenericFlag(EntityMetadataFlags::ANGRY, $this->angry);
		$properties->setGenericFlag(EntityMetadataFlags::INLOVE, $this->inLove);
		$properties->setInt(EntityMetadataProperties::MARK_VARIANT, $this->hasStung ? 1 : 0);
	}

	public function getPropertySyncData() : PropertySyncData{
		return new PropertySyncData([0 => $this->hasNectar ? 1 : 0], []);
	}

	protected function onDispose() : void{
		if($this->flowerTarget !== null){
			self::releaseFlower((int) $this->flowerTarget->x, (int) $this->flowerTarget->y, (int) $this->flowerTarget->z, $this->getId());
		}
		parent::onDispose();
	}
}