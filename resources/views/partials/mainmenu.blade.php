@php
$role=$account['role'];
@endphp

<div id="m_aside_left" class="m-grid__item m-aside-left m-aside-left--skin-dark">
    <div id="m_ver_menu" class="m-aside-menu m-aside-menu--skin-dark" m-menu-vertical="1">
        <ul class="m-menu__nav">

            <li class="m-menu__item" aria-haspopup="true">
                <a href="dashboard.php" class="m-menu__link">
                    <span class="m-menu__link-text">Dashboard</span>
                </a>
            </li>

            @if (in_array($role, ['admin', 'warden'], true))
            <li class="m-menu__section">
                <h4 class="m-menu__section-text">Outing requests</h4>
            </li>
            <li class="m-menu__item" aria-haspopup="true">
                <a href="pending_requests.php" class="m-menu__link">
                    <span class="m-menu__link-text">Pending approval</span>
                </a>
            </li>
            <li class="m-menu__item" aria-haspopup="true">
                <a href="request_status.php" class="m-menu__link">
                    <span class="m-menu__link-text">Request status</span>
                </a>
            </li>
            <li class="m-menu__item" aria-haspopup="true">
                <a href="violation_report.php" class="m-menu__link">
                    <span class="m-menu__link-text">Violation report</span>
                </a>
            </li>
            @endif

            @if ($role === 'guard')
            <li class="m-menu__section">
                <h4 class="m-menu__section-text">Gate</h4>
            </li>
            <li class="m-menu__item" aria-haspopup="true">
                <a href="gate.php" class="m-menu__link">
                    <span class="m-menu__link-text">Check-out / check-in</span>
                </a>
            </li>
            @endif

            @if (in_array($role, ['admin', 'guard', 'warden'], true))
            <li class="m-menu__item" aria-haspopup="true">
                <a href="currently_out.php" class="m-menu__link">
                    <span class="m-menu__link-text">Currently out</span>
                </a>
            </li>
            <li class="m-menu__item" aria-haspopup="true">
                <a href="outing_history.php" class="m-menu__link">
                    <span class="m-menu__link-text">Outing History</span>
                </a>
            </li>
            @endif

            @if ($role === 'admin')
            <li class="m-menu__section">
                <h4 class="m-menu__section-text">Manage students</h4>
            </li>
            <li class="m-menu__item" aria-haspopup="true">
                <a href="import_students.php" class="m-menu__link">
                    <span class="m-menu__link-text">Import students (CSV)</span>
                </a>
            </li>
            <li class="m-menu__item" aria-haspopup="true">
                <a href="manage_photos.php" class="m-menu__link">
                    <span class="m-menu__link-text">Student photos</span>
                </a>
            </li>
            <li class="m-menu__item" aria-haspopup="true">
                <a href="manage_staff.php" class="m-menu__link">
                    <span class="m-menu__link-text">Manage staff accounts</span>
                </a>
            </li>
            <li class="m-menu__item" aria-haspopup="true">
                <a href="manage_settings.php" class="m-menu__link">
                    <span class="m-menu__link-text">System settings</span>
                </a>
            </li>
            @endif

        </ul>
    </div>
</div>
