<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace spec\Sylius\Component\Review\Model;

use PhpSpec\ObjectBehavior;
use Sylius\Component\Review\Model\ReviewerInterface;

/**
 * @author Mateusz Zalewski <mateusz.zalewski@lakion.com>
 */
class ReviewerSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $this->shouldHaveType('Sylius\Component\Review\Model\Reviewer');
    }

    function it_implements_reviewer_interface()
    {
        $this->shouldImplement(ReviewerInterface::class);
    }

    function it_has_email()
    {
        $this->setEmail('john.doe@example.com');
        $this->getEmail()->shouldReturn('john.doe@example.com');
    }

    function it_has_first_name()
    {
        $this->setFirstName('John');
        $this->getFirstName()->shouldReturn('John');
    }

    function it_has_last_name()
    {
        $this->setLastName('Doe');
        $this->getLastName()->shouldReturn('Doe');
    }
}
