<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class CustomizationRequest
{
    public const STATUS_PENDING = 'Pending';
    public const STATUS_REVIEWING = 'Reviewing';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_DECLINED = 'Declined';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $customer = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank(message: 'Please choose what you want to customize.')]
    private ?string $productType = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $baseColor = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $size = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $placement = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Please describe your design idea.')]
    #[Assert\Length(min: 10, minMessage: 'Please add at least {{ limit }} characters for the design details.')]
    private ?string $designDescription = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 40)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $staffResponse = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $designSnapshot = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): ?User
    {
        return $this->customer;
    }

    public function setCustomer(?User $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;

        return $this;
    }

    public function getProductType(): ?string
    {
        return $this->productType;
    }

    public function setProductType(string $productType): static
    {
        $this->productType = $productType;

        return $this;
    }

    public function getBaseColor(): ?string
    {
        return $this->baseColor;
    }

    public function setBaseColor(?string $baseColor): static
    {
        $this->baseColor = $baseColor;

        return $this;
    }

    public function getSize(): ?string
    {
        return $this->size;
    }

    public function setSize(?string $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getPlacement(): ?string
    {
        return $this->placement;
    }

    public function setPlacement(?string $placement): static
    {
        $this->placement = $placement;

        return $this;
    }

    public function getDesignDescription(): ?string
    {
        return $this->designDescription;
    }

    public function setDesignDescription(string $designDescription): static
    {
        $this->designDescription = $designDescription;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStaffResponse(): ?string
    {
        return $this->staffResponse;
    }

    public function setStaffResponse(?string $staffResponse): static
    {
        $this->staffResponse = $staffResponse;

        return $this;
    }

    public function getDesignSnapshot(): ?string
    {
        return $this->designSnapshot;
    }

    public function setDesignSnapshot(?string $designSnapshot): static
    {
        $this->designSnapshot = $designSnapshot;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function setTimestampsOnCreate(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
