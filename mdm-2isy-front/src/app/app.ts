import { Component } from '@angular/core';
import { RouterModule, Router, NavigationEnd } from '@angular/router';
import { CommonModule } from '@angular/common';
import { Sidebar } from './layout/sidebar/sidebar';
import { Header } from './layout/header/header';
import { filter } from 'rxjs/operators';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, RouterModule, Sidebar, Header],
  templateUrl: './app.html'
})
export class App {
  isLoginPage = false;
  menuOpen = false;

  constructor(private router: Router) {
    // Écoute les changements d'URL pour savoir si on est sur la page login
    this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
    ).subscribe((event) => {
      const primaryRoute = this.router.parseUrl(event.urlAfterRedirects).root.children['primary'];
      const redirectedPath = primaryRoute?.segments.map((segment) => segment.path).join('/') ?? '';
      this.isLoginPage = redirectedPath === 'login' || redirectedPath === '';
      this.menuOpen = false;
    });
  }

  toggleMenu(): void {
    this.menuOpen = !this.menuOpen;
  }

  closeMenu(): void {
    this.menuOpen = false;
  }
}
